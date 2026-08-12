<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Auth;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;

class InventoryDeleteApiTest extends InventoryFeatureTestCase
{
    private function createItem(array $overrides = []): int
    {
        $project = $this->createActiveProject();

        return $this->postJson('/api/v1/inventory/items', array_merge([
            'name' => 'Item',
            'category_id' => $this->createInventoryCategory()->id,
            'project_id' => $project->id,
            'unit' => 'pc',
            'opening_price' => 10,
            'opening_quantity' => 0,
        ], $overrides))->json('data.id');
    }

    // --- Item deletion ---------------------------------------------------

    public function test_item_with_no_movements_or_batches_can_be_deleted(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem();

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertOk()
            ->assertJsonPath('message', __('messages.inventory_item_deleted_successfully'));

        $this->assertDatabaseMissing('inventory_items', ['id' => $itemId]);
    }

    public function test_item_with_only_unused_opening_batch_can_be_deleted(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 5]);

        $this->assertDatabaseHas('inventory_batches', [
            'inventory_item_id' => $itemId,
            'source_type' => 'opening',
        ]);

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertOk()
            ->assertJsonPath('message', __('messages.inventory_item_deleted_successfully'));

        $this->assertDatabaseMissing('inventory_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('inventory_batches', ['inventory_item_id' => $itemId]);
        $this->assertDatabaseMissing('inventory_movements', ['inventory_item_id' => $itemId]);
    }

    public function test_item_with_incoming_movement_cannot_be_deleted(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 5]);

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => 2,
        ])->assertCreated();

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_item_has_related_data'));

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId]);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
    }

    public function test_item_with_outgoing_movement_cannot_be_deleted(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 10]);

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 3,
            'beneficiary_project_id' => $this->createActiveProject()->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_item_has_related_data'));

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId]);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
    }

    public function test_item_with_incoming_and_outgoing_movements_cannot_be_deleted(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 10]);

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 5,
            'unit_price' => 2,
        ])->assertCreated();

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 4,
            'beneficiary_project_id' => $this->createActiveProject()->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_item_has_related_data'));

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId]);
        $this->assertSame(2, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
    }

    public function test_item_with_zero_stock_but_historical_movements_cannot_be_deleted(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 0]);

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 10,
            'unit_price' => 2,
        ])->assertCreated();

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 10,
            'beneficiary_project_id' => $this->createActiveProject()->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $this->assertSame('0.00', InventoryItem::query()->findOrFail($itemId)->current_balance);

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_item_has_related_data'));

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId]);
        $this->assertSame(2, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
    }

    public function test_deleting_a_movement_does_not_delete_the_item(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 100]);

        $movementId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 50,
            'unit_price' => 10,
        ])->json('data.id');

        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)
            ->assertOk()
            ->assertJsonPath('data.current_balance', '100.00');

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('inventory_movements', ['id' => $movementId]);
    }

    public function test_item_can_be_deleted_after_all_movements_are_removed(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 100]);

        $movementId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 50,
            'unit_price' => 10,
        ])->json('data.id');

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_item_has_related_data'));

        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)->assertOk();

        $this->deleteJson('/api/v1/inventory/items/'.$itemId)
            ->assertOk()
            ->assertJsonPath('message', __('messages.inventory_item_deleted_successfully'));

        $this->assertDatabaseMissing('inventory_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('inventory_batches', ['inventory_item_id' => $itemId]);
        $this->assertDatabaseMissing('inventory_movements', ['inventory_item_id' => $itemId]);
    }

    public function test_deleting_non_existent_item_returns_not_found(): void
    {
        $this->actAsInventoryUser();

        $this->deleteJson('/api/v1/inventory/items/999999')
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.inventory_item_not_found'));
    }

    public function test_unauthorized_role_cannot_delete_item(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem();

        $this->actAsFinanceUser();
        $this->deleteJson('/api/v1/inventory/items/'.$itemId)->assertForbidden();

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId]);
    }

    // --- Movement deletion -------------------------------------------------

    public function test_deleting_incoming_movement_reverses_balance(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 100]);

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 50,
            'unit_price' => 10,
        ])->assertCreated();

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 20,
            'beneficiary_project_id' => $this->createActiveProject()->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $item = InventoryItem::query()->findOrFail($itemId);
        $this->assertSame('130.00', $item->current_balance);

        $incoming = InventoryMovement::query()
            ->where('inventory_item_id', $itemId)
            ->where('type', 'incoming')
            ->firstOrFail();

        $response = $this->deleteJson('/api/v1/inventory/movements/'.$incoming->id);
        $response->assertOk()
            ->assertJsonPath('data.current_balance', '80.00')
            ->assertJsonPath('data.total_incoming_quantity', '0.00')
            ->assertJsonPath('data.total_outgoing_quantity', '20.00');

        $this->assertDatabaseMissing('inventory_movements', ['id' => $incoming->id]);
        $this->assertDatabaseMissing('inventory_batches', ['source_movement_id' => $incoming->id]);
    }

    public function test_deleting_outgoing_movement_restores_balance_and_batch(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 100]);

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 50,
            'unit_price' => 10,
        ])->assertCreated();

        $outgoingId = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 20,
            'beneficiary_project_id' => $this->createActiveProject()->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->json('data.id');

        $item = InventoryItem::query()->findOrFail($itemId);
        $this->assertSame('130.00', $item->current_balance);

        $response = $this->deleteJson('/api/v1/inventory/movements/'.$outgoingId);
        $response->assertOk()
            ->assertJsonPath('data.current_balance', '150.00')
            ->assertJsonPath('data.total_incoming_quantity', '50.00')
            ->assertJsonPath('data.total_outgoing_quantity', '0.00');

        $this->assertDatabaseMissing('inventory_movements', ['id' => $outgoingId]);
        $this->assertDatabaseMissing('inventory_batch_consumptions', ['outgoing_movement_id' => $outgoingId]);

        $openingBatch = InventoryBatch::query()
            ->where('inventory_item_id', $itemId)
            ->where('source_type', 'opening')
            ->firstOrFail();
        $this->assertSame('100.00', $openingBatch->remaining_quantity);
    }

    public function test_deleting_consumed_incoming_movement_is_blocked(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 0]);

        $incomingId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 50,
            'unit_price' => 10,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 20,
            'beneficiary_project_id' => $this->createActiveProject()->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $this->deleteJson('/api/v1/inventory/movements/'.$incomingId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_movement_already_consumed'));

        $this->assertDatabaseHas('inventory_movements', ['id' => $incomingId]);

        $item = InventoryItem::query()->findOrFail($itemId);
        $this->assertSame('30.00', $item->current_balance);
    }

    public function test_multiple_movements_only_the_deleted_one_is_reversed(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 100]);

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 30,
            'unit_price' => 5,
        ])->assertCreated();

        $secondIncomingId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 40,
            'unit_price' => 6,
        ])->json('data.id');

        // 100 + 30 + 40 = 170
        $this->assertSame('170.00', InventoryItem::query()->findOrFail($itemId)->current_balance);

        $this->deleteJson('/api/v1/inventory/movements/'.$secondIncomingId)->assertOk();

        // 170 - 40 = 130, first incoming (30) untouched
        $item = InventoryItem::query()->findOrFail($itemId);
        $this->assertSame('130.00', $item->current_balance);
        $this->assertSame('30.00', $item->total_incoming_quantity);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_item_id', $itemId)->count());
    }

    public function test_deleting_movement_twice_returns_not_found_second_time(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 10]);

        $movementId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 5,
            'unit_price' => 1,
        ])->json('data.id');

        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)->assertOk();
        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.inventory_movement_not_found'));
    }

    public function test_deleting_non_existent_movement_returns_not_found(): void
    {
        $this->actAsInventoryUser();

        $this->deleteJson('/api/v1/inventory/movements/999999')
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.inventory_movement_not_found'));
    }

    public function test_unauthorized_role_cannot_delete_movement(): void
    {
        $this->actAsInventoryUser();
        $itemId = $this->createItem(['opening_quantity' => 10]);
        $movementId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 5,
            'unit_price' => 1,
        ])->json('data.id');

        $this->actAsFinanceUser();
        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)->assertForbidden();

        $this->assertDatabaseHas('inventory_movements', ['id' => $movementId]);
    }

    public function test_deleting_movement_on_one_item_does_not_affect_another(): void
    {
        $this->actAsInventoryUser();
        $itemAId = $this->createItem(['opening_quantity' => 50]);
        $itemBId = $this->createItem(['opening_quantity' => 60]);

        $movementId = $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemAId,
            'quantity' => 10,
            'unit_price' => 1,
        ])->json('data.id');

        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)->assertOk();

        $this->assertSame('60.00', InventoryItem::query()->findOrFail($itemBId)->current_balance);
    }

    public function test_deleting_administrative_outgoing_movement_refreshes_daily_journal(): void
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

        $movementId = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 2,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ])->json('data.id');

        $entry = DailyJournalEntry::query()
            ->where('project_id', $beneficiary->id)
            ->whereDate('journal_date', now()->toDateString())
            ->first();
        $this->assertSame('200.00', number_format((float) $entry->administrative_expense, 2, '.', ''));

        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)->assertOk();

        $entry->refresh();
        $this->assertSame('0.00', number_format((float) $entry->administrative_expense, 2, '.', ''));
    }

    public function test_deleting_operational_outgoing_movement_does_not_touch_daily_journal(): void
    {
        $this->actAsSuperAdmin();
        Role::findOrCreate('inventory', 'web');
        /** @var User $user */
        $user = Auth::user();
        $user->assignRole('inventory');

        $owner = $this->createActiveProject();
        $beneficiary = $this->createActiveProject(['administrative_exempt' => true]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Screw',
            'category_id' => $this->createInventoryCategory(['name' => 'parts'])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 1,
            'opening_quantity' => 10,
        ])->json('data.id');

        $movementId = $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 4,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->json('data.id');

        $this->assertDatabaseMissing('daily_journal_entries', ['project_id' => $beneficiary->id]);

        $this->deleteJson('/api/v1/inventory/movements/'.$movementId)->assertOk();

        $this->assertDatabaseMissing('daily_journal_entries', ['project_id' => $beneficiary->id]);
    }
}
