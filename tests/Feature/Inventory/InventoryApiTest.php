<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Auth;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Enums\InventoryBatchSourceType;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;

class InventoryApiTest extends InventoryFeatureTestCase
{
    public function test_create_item_with_opening_batch_only(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $response = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Paper A4',
            'category_id' => $this->createInventoryCategory(['name' => 'office'])->id,
            'project_id' => $project->id,
            'unit' => 'ream',
            'opening_price' => 10,
            'opening_quantity' => 50,
            'minimum_stock_level' => 5,
            'notes' => 'opening stock',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Paper A4')
            ->assertJsonPath('data.opening_quantity', '50.00')
            ->assertJsonPath('data.total_incoming_quantity', '0.00')
            ->assertJsonPath('data.total_outgoing_quantity', '0.00')
            ->assertJsonPath('data.current_balance', '50.00')
            ->assertJsonPath('data.latest_incoming_price', '10.00');

        $itemId = $response->json('data.id');
        $this->assertNotEmpty($response->json('data.code'));

        $this->assertDatabaseMissing('inventory_movements', [
            'inventory_item_id' => $itemId,
        ]);

        $batch = InventoryBatch::query()->where('inventory_item_id', $itemId)->first();
        $this->assertNotNull($batch);
        $this->assertSame(InventoryBatchSourceType::Opening, $batch->source_type);
        $this->assertNull($batch->source_movement_id);
        $this->assertSame('50.00', $batch->remaining_quantity);
        $this->assertSame('10.00', $batch->unit_cost);

        $this->getJson('/api/v1/inventory/items')->assertOk()
            ->assertJsonFragment(['name' => 'Paper A4']);
    }

    public function test_rejects_client_supplied_balances_and_code(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'X',
            'category_id' => $this->createInventoryCategory(['name' => 'office'])->id,
            'project_id' => $project->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 1,
            'code' => 'CLIENT-CODE',
            'current_balance' => 999,
        ])->assertStatus(422)->assertJsonValidationErrors(['code', 'current_balance']);
    }

    public function test_incoming_updates_balance_and_latest_price(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Ink',
            'category_id' => $this->createInventoryCategory(['name' => 'supplies'])->id,
            'project_id' => $project->id,
            'unit' => 'bottle',
            'opening_price' => 5,
            'opening_quantity' => 10,
            'minimum_stock_level' => 0,
        ])->json('data.id');

        $response = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 20,
            'unit_price' => 8,
            'notes' => 'restock',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'incoming')
            ->assertJsonPath('data.quantity', '20.00')
            ->assertJsonPath('data.unit_price', '8.00');

        $item = InventoryItem::query()->findOrFail($itemId);
        $this->assertSame('10.00', $item->opening_quantity);
        $this->assertSame('20.00', $item->total_incoming_quantity);
        $this->assertSame('0.00', $item->total_outgoing_quantity);
        $this->assertSame('30.00', $item->current_balance);
        $this->assertSame('8.00', $item->latest_incoming_price);

        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
    }

    public function test_incoming_rejects_client_movement_date(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Ink',
            'category_id' => $this->createInventoryCategory(['name' => 'supplies'])->id,
            'project_id' => $project->id,
            'unit' => 'bottle',
            'opening_price' => 5,
            'opening_quantity' => 10,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => 1,
            'movement_date' => '2020-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['movement_date']);
    }

    public function test_incoming_does_not_affect_daily_journal_financials(): void
    {
        $this->actAsSuperAdmin();
        Role::findOrCreate('inventory', 'web');
        /** @var User $user */
        $user = Auth::user();
        $user->assignRole('inventory');

        $project = $this->createActiveProject([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 10,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Toner',
            'category_id' => $this->createInventoryCategory(['name' => 'supplies'])->id,
            'project_id' => $project->id,
            'unit' => 'pc',
            'opening_price' => 100,
            'opening_quantity' => 5,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 2,
            'unit_price' => 120,
        ])->assertCreated();

        $journal = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => now()->toDateString(),
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 1000, 'daily_expense' => 0],
            ],
        ]);

        $journal->assertOk();
        $entry = collect($journal->json('data.entries'))->firstWhere('project.id', $project->id);

        $this->assertSame('0', $entry['administrative_expense']);
        $this->assertSame('100', $entry['administrative_fee']);
        $this->assertSame('0', $entry['administrative_debt']);
    }

    public function test_outgoing_fifo_consumes_oldest_batches_first(): void
    {
        $this->actAsInventoryUser();
        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Cable',
            'category_id' => $this->createInventoryCategory(['name' => 'equipment'])->id,
            'project_id' => $owner->id,
            'unit' => 'm',
            'opening_price' => 2,
            'opening_quantity' => 10,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 10,
            'unit_price' => 5,
        ])->assertCreated();

        // Force latest display price high; FIFO must still use layer costs 2 then 5
        InventoryItem::query()->whereKey($itemId)->update(['latest_incoming_price' => 99]);

        $response = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 12,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'outgoing')
            ->assertJsonPath('data.total_cost', '30.00') // 10*2 + 2*5
            ->assertJsonPath('data.unit_price', '2.50'); // FIFO weighted average 30/12

        $consumptions = $response->json('data.consumptions');
        $this->assertCount(2, $consumptions);
        $this->assertSame('10.00', $consumptions[0]['quantity']);
        $this->assertSame('2.00', $consumptions[0]['unit_cost']);
        $this->assertSame('2.00', $consumptions[1]['quantity']);
        $this->assertSame('5.00', $consumptions[1]['unit_cost']);

        $item = InventoryItem::query()->findOrFail($itemId);
        $this->assertSame('8.00', $item->current_balance);
        $this->assertSame('12.00', $item->total_outgoing_quantity);
    }

    public function test_outgoing_rejects_quantity_exceeding_stock(): void
    {
        $this->actAsInventoryUser();
        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Cable',
            'category_id' => $this->createInventoryCategory(['name' => 'equipment'])->id,
            'project_id' => $owner->id,
            'unit' => 'm',
            'opening_price' => 2,
            'opening_quantity' => 5,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 6,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => 'operational',
        ])->assertStatus(422);
    }

    public function test_administrative_outgoing_feeds_daily_journal_expense(): void
    {
        $this->actAsSuperAdmin();
        Role::findOrCreate('inventory', 'web');
        /** @var User $user */
        $user = Auth::user();
        $user->assignRole('inventory');

        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject([
            'administrative_exempt' => true,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Office chair',
            'category_id' => $this->createInventoryCategory(['name' => 'furniture'])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 100,
            'opening_quantity' => 3,
        ])->json('data.id');

        $outgoing = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 2,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ]);

        $outgoing->assertCreated()
            ->assertJsonPath('data.expense_type', 'administrative')
            ->assertJsonPath('data.total_cost', '200.00');

        $journal = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => now()->toDateString(),
            'entries' => [
                ['project_id' => $beneficiary->id, 'daily_income' => 0, 'daily_expense' => 0],
            ],
        ]);

        $journal->assertOk();
        $entry = collect($journal->json('data.entries'))->firstWhere('project.id', $beneficiary->id);
        $this->assertSame('200', $entry['administrative_expense']);
    }

    public function test_administrative_outgoing_auto_refreshes_daily_journal_expense(): void
    {
        $this->actAsSuperAdmin();
        Role::findOrCreate('inventory', 'web');
        /** @var User $user */
        $user = Auth::user();
        $user->assignRole('inventory');

        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject([
            'administrative_exempt' => true,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Office chair',
            'category_id' => $this->createInventoryCategory(['name' => 'furniture'])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 100,
            'opening_quantity' => 3,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 2,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ])->assertCreated();

        // The DailyJournal should be recalculated automatically from the inventory movement
        // (no need to call PUT /api/v1/daily-journals just to refresh administrative expense).
        $entry = DailyJournalEntry::query()
            ->where('project_id', $beneficiary->id)
            ->whereDate('journal_date', now()->toDateString())
            ->first();

        $this->assertNotNull($entry, 'Daily journal entry should exist for movement day.');
        $this->assertSame('200.00', number_format((float) $entry->administrative_expense, 2, '.', ''));
    }

    public function test_administrative_expense_exceeding_fee_uses_surplus_not_debt(): void
    {
        $this->actAsSuperAdmin();
        Role::findOrCreate('inventory', 'web');
        /** @var User $user */
        $user = Auth::user();
        $user->assignRole('inventory');

        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 10,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Desk',
            'category_id' => $this->createInventoryCategory(['name' => 'furniture'])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 80,
            'opening_quantity' => 2,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ])->assertCreated()
            ->assertJsonPath('data.total_cost', '80.00');

        // Income 500 → fee 50; admin expense 80; daily_total = 450; surplus covers 80 → fund 370
        $journal = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => now()->toDateString(),
            'entries' => [
                ['project_id' => $beneficiary->id, 'daily_income' => 500, 'daily_expense' => 0],
            ],
        ]);

        $journal->assertOk();
        $entry = collect($journal->json('data.entries'))->firstWhere('project.id', $beneficiary->id);

        $this->assertSame('80', $entry['administrative_expense']);
        $this->assertSame('50', $entry['administrative_fee']);
        $this->assertSame('370', $entry['fund_balance']);
        $this->assertSame('0', $entry['administrative_debt']);
        $this->assertSame('0', $entry['uncovered_administrative_expense']);
        $this->assertSame('0', $entry['accumulated_administrative_debt']);
    }

    public function test_operational_outgoing_does_not_feed_administrative_expense(): void
    {
        $this->actAsSuperAdmin();
        Role::findOrCreate('inventory', 'web');
        /** @var User $user */
        $user = Auth::user();
        $user->assignRole('inventory');

        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject([
            'administrative_exempt' => true,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Screw',
            'category_id' => $this->createInventoryCategory(['name' => 'parts'])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 10,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 4,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $journal = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => now()->toDateString(),
            'entries' => [
                ['project_id' => $beneficiary->id, 'daily_income' => 0, 'daily_expense' => 0],
            ],
        ]);

        $entry = collect($journal->json('data.entries'))->firstWhere('project.id', $beneficiary->id);
        $this->assertSame('0', $entry['administrative_expense']);
    }

    public function test_search_and_filter_on_list_only(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'Alpha Widget',
            'category_id' => $this->createInventoryCategory(['name' => 'widgets'])->id,
            'project_id' => $project->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 1,
        ])->assertCreated();

        $gadgetsCategory = $this->createInventoryCategory(['name' => 'gadgets']);

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'Beta Gadget',
            'category_id' => $gadgetsCategory->id,
            'project_id' => $project->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 1,
        ])->assertCreated();

        $this->getJson('/api/v1/inventory/items?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Widget');

        $this->getJson('/api/v1/inventory/items?filter[category_id]='.$gadgetsCategory->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Gadget');
    }

    public function test_finance_cannot_access_inventory(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject();

        $this->getJson('/api/v1/inventory/items')->assertForbidden();
        $this->postJson('/api/v1/inventory/items', [
            'name' => 'X',
            'category_id' => $this->createInventoryCategory(['name' => 'c'])->id,
            'project_id' => $project->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 1,
        ])->assertForbidden();
    }

    public function test_movements_have_no_update_routes(): void
    {
        $this->actAsInventoryUser();
        $owner = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'X',
            'category_id' => $this->createInventoryCategory(['name' => 'c'])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 5,
        ])->json('data.id');

        $movementId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => 2,
        ])->json('data.id');

        // A DELETE route exists at this URI, so other methods correctly 405 rather than 404.
        $this->putJson('/api/v1/inventory/movements/'.$movementId, ['quantity' => 9])->assertStatus(405);
        $this->patchJson('/api/v1/inventory/movements/'.$movementId, ['quantity' => 9])->assertStatus(405);
    }

    public function test_list_movements_default_sort_and_summary(): void
    {
        $this->actAsInventoryUser();
        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Paper',
            'category_id' => $this->createInventoryCategory(['name' => 'office'])->id,
            'project_id' => $owner->id,
            'unit' => 'ream',
            'opening_price' => 10,
            'opening_quantity' => 20,
        ])->json('data.id');

        $incomingId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 5,
            'unit_price' => 12,
        ])->json('data.id');

        $outgoingId = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 3,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->json('data.id');

        InventoryMovement::query()->whereKey($incomingId)->update([
            'movement_date' => now()->subDay()->toDateString(),
            'created_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/v1/inventory/movements')->assertOk();

        $this->assertSame($outgoingId, $response->json('data.0.id'));
        $this->assertSame($incomingId, $response->json('data.1.id'));
        $this->assertSame('60.00', $response->json('summary.total_incoming_value'));
        $this->assertSame('30.00', $response->json('summary.total_outgoing_value'));
        $this->assertSame('30.00', $response->json('summary.net_movement_value'));
    }

    public function test_list_movements_date_range_filter_scopes_rows_and_summary(): void
    {
        $this->actAsInventoryUser();
        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Ink',
            'category_id' => $this->createInventoryCategory(['name' => 'office'])->id,
            'project_id' => $owner->id,
            'unit' => 'bottle',
            'opening_price' => 8,
            'opening_quantity' => 10,
        ])->json('data.id');

        $oldIncomingId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 2,
            'unit_price' => 10,
        ])->json('data.id');

        $newOutgoingId = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ])->json('data.id');

        $oldDate = now()->subDays(3)->toDateString();
        $today = now()->toDateString();

        InventoryMovement::query()->whereKey($oldIncomingId)->update(['movement_date' => $oldDate]);

        $response = $this->getJson('/api/v1/inventory/movements?filter[movement_date_from]='.$today.'&filter[movement_date_to]='.$today)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($newOutgoingId, $ids);
        $this->assertNotContains($oldIncomingId, $ids);
        $this->assertSame('0.00', $response->json('summary.total_incoming_value'));
        $this->assertSame('8.00', $response->json('summary.total_outgoing_value'));
        $this->assertSame('-8.00', $response->json('summary.net_movement_value'));
    }
}
