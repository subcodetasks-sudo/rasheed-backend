<?php

namespace Tests\Feature\AdvancedReports;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Enums\InventoryBatchSourceType;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdvancedReportsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/advanced-reports';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createProject(array $attrs = []): Project
    {
        return Project::factory()->create(array_merge([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
        ], $attrs));
    }

    private function seedEntry(int $projectId, string $date, array $attrs = []): DailyJournalEntry
    {
        return DailyJournalEntry::factory()->create(array_merge([
            'project_id' => $projectId,
            'journal_date' => $date,
            'daily_income' => 0,
            'daily_expense' => 0,
            'contribution' => 0,
            'administrative_expense' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_total' => 0,
            'fund_balance' => 0,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
        ], $attrs));
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT.'?report_type=month_comparison&period=3')->assertUnauthorized();
    }

    public function test_finance_can_access(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?report_type=month_comparison&period=3')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_inventory_can_access_inventory_report(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT.'?report_type=inventory&period=3')
            ->assertOk()
            ->assertJsonPath('data.report_type', 'inventory');
    }

    public function test_validation_requires_report_type_and_period(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT)->assertStatus(422)
            ->assertJsonValidationErrors(['report_type', 'period']);

        $this->getJson(self::ENDPOINT.'?report_type=month_comparison&period=4')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    public function test_month_comparison_period_lengths_and_empty_zeros(): void
    {
        $this->actAs('finance');

        foreach ([3, 6, 12] as $period) {
            $response = $this->getJson(self::ENDPOINT."?report_type=month_comparison&period={$period}");
            $response->assertOk()
                ->assertJsonCount($period, 'data.months')
                ->assertJsonCount($period, 'data.comparison_table')
                ->assertJsonCount($period, 'data.charts.revenue_expense_trend')
                ->assertJsonPath('data.summary.total_revenue', '0')
                ->assertJsonPath('data.summary.net_period', '0');
        }

        $three = $this->getJson(self::ENDPOINT.'?report_type=month_comparison&period=3');
        $this->assertSame(6, $three->json('data.months.0.month'));
        $this->assertSame(8, $three->json('data.months.2.month'));
        $this->assertNull($three->json('data.comparison_table.0.growth_rate_percent'));
    }

    public function test_month_comparison_net_excludes_admin_and_operational(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // June: revenue 1000, expense 100, admin collected 110, op 50 → net = 900
        $this->seedEntry($project->id, '2026-06-10', [
            'daily_income' => 1000,
            'daily_expense' => 100,
            'administrative_fee' => 120,
            'administrative_debt' => 20,
            'contribution' => 10,
            'operational_deduction' => 50,
        ]);

        // July: revenue 500, expense 500 → net 0 (growth null vs June when computing Aug... July previous is June)
        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 500,
            'daily_expense' => 500,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
        ]);

        // August: revenue 200, expense 0 → net 200; previous July net 0 → growth null
        $this->seedEntry($project->id, '2026-08-10', [
            'daily_income' => 200,
            'daily_expense' => 0,
            'administrative_fee' => 24,
            'operational_deduction' => 10,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?report_type=month_comparison&period=3');
        $response->assertOk()
            ->assertJsonPath('data.summary.total_revenue', '1700')
            ->assertJsonPath('data.summary.total_expenses', '600')
            ->assertJsonPath('data.summary.net_period', '1100');

        $june = collect($response->json('data.comparison_table'))->firstWhere('month', 6);
        $this->assertSame('1000', $june['total_revenue']);
        $this->assertSame('100', $june['expenses']);
        $this->assertSame('110', $june['administrative_deduction']);
        $this->assertSame('50', $june['operational_deduction']);
        $this->assertSame('900', $june['net']);
        $this->assertNull($june['growth_rate_percent']);

        $july = collect($response->json('data.comparison_table'))->firstWhere('month', 7);
        $this->assertSame('0', $july['net']);
        // growth vs June 900: ((0-900)/900)*100 = -100
        $this->assertEqualsWithDelta(-100.0, (float) $july['growth_rate_percent'], 0.001);

        $august = collect($response->json('data.comparison_table'))->firstWhere('month', 8);
        $this->assertSame('200', $august['net']);
        $this->assertSame('24', $august['administrative_deduction']);
        $this->assertSame('10', $august['operational_deduction']);
        $this->assertNull($august['growth_rate_percent']);
    }

    public function test_inventory_report_empty(): void
    {
        $this->actAs('finance');

        $this->getJson(self::ENDPOINT.'?report_type=inventory&period=3')
            ->assertOk()
            ->assertJsonPath('data.summary.total_items', 0)
            ->assertJsonPath('data.summary.low_stock_items', 0)
            ->assertJsonPath('data.summary.inventory_value', '0')
            ->assertJsonPath('data.summary.most_consumed_item', null)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_inventory_statuses_fifo_value_and_most_consumed(): void
    {
        $this->actAs('super-admin');

        $good = InventoryItem::factory()->create([
            'name' => 'Good Item',
            'unit' => 'kg',
            'opening_quantity' => 100,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => 100,
            'minimum_stock_level' => 10,
        ]);
        $low = InventoryItem::factory()->create([
            'name' => 'Low Item',
            'unit' => 'box',
            'opening_quantity' => 5,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => 5,
            'minimum_stock_level' => 10,
        ]);
        $out = InventoryItem::factory()->create([
            'name' => 'Out Item',
            'unit' => 'piece',
            'opening_quantity' => 0,
            'total_incoming_quantity' => 10,
            'total_outgoing_quantity' => 10,
            'current_balance' => 0,
            'minimum_stock_level' => 2,
        ]);

        InventoryBatch::query()->create([
            'inventory_item_id' => $good->id,
            'source_type' => InventoryBatchSourceType::Opening,
            'source_movement_id' => null,
            'unit_cost' => 2.50,
            'original_quantity' => 100,
            'remaining_quantity' => 40,
            'received_at' => now(),
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $good->id,
            'source_type' => InventoryBatchSourceType::Incoming,
            'source_movement_id' => null,
            'unit_cost' => 3.00,
            'original_quantity' => 20,
            'remaining_quantity' => 20,
            'received_at' => now(),
        ]);

        InventoryMovement::query()->create([
            'inventory_item_id' => $good->id,
            'type' => InventoryMovementType::Outgoing,
            'quantity' => 30,
            'unit_price' => 0,
            'total_cost' => 0,
            'movement_date' => '2026-07-20',
            'created_by' => null,
        ]);
        InventoryMovement::query()->create([
            'inventory_item_id' => $low->id,
            'type' => InventoryMovementType::Outgoing,
            'quantity' => 10,
            'unit_price' => 0,
            'total_cost' => 0,
            'movement_date' => '2026-08-01',
            'created_by' => null,
        ]);
        // Outside period — ignored for most consumed
        InventoryMovement::query()->create([
            'inventory_item_id' => $out->id,
            'type' => InventoryMovementType::Outgoing,
            'quantity' => 999,
            'unit_price' => 0,
            'total_cost' => 0,
            'movement_date' => '2026-01-01',
            'created_by' => null,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?report_type=inventory&period=3');
        $response->assertOk()
            ->assertJsonPath('data.summary.total_items', 3)
            ->assertJsonPath('data.summary.low_stock_items', 1)
            // 40*2.5 + 20*3 = 100 + 60 = 160
            ->assertJsonPath('data.summary.inventory_value', '160')
            ->assertJsonPath('data.summary.most_consumed_item.id', $good->id)
            ->assertJsonPath('data.summary.most_consumed_item.quantity_consumed', '30');

        $byName = collect($response->json('data.items'))->keyBy('item_name');
        $this->assertSame('good', $byName['Good Item']['status']);
        $this->assertSame('100', $byName['Good Item']['total_incoming']);
        $this->assertSame('low', $byName['Low Item']['status']);
        $this->assertSame('out_of_stock', $byName['Out Item']['status']);
        $this->assertSame('10', $byName['Out Item']['total_incoming']);
        $this->assertSame('10', $byName['Out Item']['total_outgoing']);
        $this->assertSame('0', $byName['Out Item']['balance']);
    }
}
