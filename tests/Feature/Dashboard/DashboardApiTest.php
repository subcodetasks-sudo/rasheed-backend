<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Dashboard\Events\DashboardUpdated;
use Modules\Inventory\Models\InventoryItem;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/dashboard';

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
            'administrative_fee_percentage' => 12,
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
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_finance_gets_200(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_gets_200(): void
    {
        $this->actAs('super-admin');
        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_invalid_journal_date_rejected(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?journal_date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['journal_date']);
    }

    public function test_omitted_date_defaults_to_today(): void
    {
        $this->actAs('finance');
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $project = $this->createProject();
        $this->seedEntry($project->id, $today, [
            'daily_income' => 100,
            'daily_expense' => 20,
            'administrative_fee' => 12,
            'operational_deduction' => 5,
        ]);
        $this->seedEntry($project->id, $yesterday, [
            'daily_income' => 999,
            'daily_expense' => 999,
            'administrative_fee' => 999,
            'operational_deduction' => 999,
        ]);

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame('100.00', $response->json('data.total_daily_income'));
        $this->assertSame('20.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('12.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('5.00', $response->json('data.total_operational_deduction'));
    }

    public function test_journal_date_filter_selects_requested_date(): void
    {
        $this->actAs('finance');
        $today = now()->toDateString();
        $target = now()->subDays(3)->toDateString();

        $project = $this->createProject();
        $this->seedEntry($project->id, $today, [
            'daily_income' => 100,
            'daily_expense' => 10,
            'administrative_fee' => 12,
            'operational_deduction' => 5,
        ]);
        $this->seedEntry($project->id, $target, [
            'daily_income' => 250,
            'daily_expense' => 40,
            'administrative_fee' => 30,
            'operational_deduction' => 15,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$target)->assertOk();

        $this->assertSame('250.00', $response->json('data.total_daily_income'));
        $this->assertSame('40.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('30.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('15.00', $response->json('data.total_operational_deduction'));
    }

    public function test_income_and_expenses_include_exempt_and_non_exempt_projects(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();

        $nonExempt = $this->createProject(['administrative_exempt' => false]);
        $exempt = $this->createProject(['administrative_exempt' => true]);

        $this->seedEntry($nonExempt->id, $date, [
            'daily_income' => 100,
            'daily_expense' => 20,
            'administrative_fee' => 12,
            'operational_deduction' => 10,
        ]);
        $this->seedEntry($exempt->id, $date, [
            'daily_income' => 50,
            'daily_expense' => 5,
            'administrative_fee' => 0,
            'operational_deduction' => 7,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('150.00', $response->json('data.total_daily_income'));
        $this->assertSame('25.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('17.00', $response->json('data.total_operational_deduction'));
    }

    public function test_administrative_percentage_uses_collected_formula_for_non_exempt_projects(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();

        $nonExempt = $this->createProject(['administrative_exempt' => false]);
        $exempt = $this->createProject(['administrative_exempt' => true]);

        $this->seedEntry($nonExempt->id, $date, [
            'daily_income' => 1000,
            'contribution' => 30,
            'administrative_fee' => 120,
            'administrative_debt' => 80,
        ]);
        $this->seedEntry($exempt->id, $date, [
            'daily_income' => 500,
            'administrative_fee' => 999,
            'administrative_debt' => 30,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        // fee 120 - debt 80 + contribution 30 = 70; exempt project excluded
        $this->assertSame('70.00', $response->json('data.total_administrative_percentage'));
    }

    public function test_administrative_percentage_matches_administration_rates_for_date(): void
    {
        $this->actAs('finance');
        $date = '2026-07-15';
        $project = $this->createProject();

        $this->seedEntry($project->id, $date, [
            'daily_income' => 1000,
            'contribution' => 30,
            'administrative_fee' => 120,
            'administrative_debt' => 80,
        ]);

        $dashboard = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();
        $adminRates = $this->getJson('/api/v1/administration-rates?month=7&year=2026')->assertOk();

        $dayRecord = collect($adminRates->json('data.daily_records'))->firstWhere('date', $date);

        $this->assertSame('70.00', $dashboard->json('data.total_administrative_percentage'));
        $this->assertSame('70.00', $dayRecord['administrative_percentage']);
    }

    public function test_dashboard_updated_broadcast_on_daily_journal_change(): void
    {
        Event::fake([DashboardUpdated::class]);
        $this->actAs('finance');

        $project = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-20',
            'entries' => [['project_id' => $project->id, 'daily_income' => 100]],
        ])->assertOk();

        Event::assertDispatched(DashboardUpdated::class, function (DashboardUpdated $event) {
            return $event->journalDate === '2026-07-20'
                && $event->data['total_daily_income'] === '100.00'
                && array_key_exists('cash_movement', $event->data)
                && array_key_exists('cash_station_status', $event->data)
                && array_key_exists('cash_station_list', $event->data)
                && array_key_exists('recent_activity', $event->data);
        });
    }

    public function test_nulls_and_no_rows_return_zero_decimals(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('0.00', $response->json('data.total_daily_income'));
        $this->assertSame('0.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('0.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('0.00', $response->json('data.total_operational_deduction'));

        $project = $this->createProject();
        $this->seedEntry($project->id, $date, [
            'daily_income' => null,
            'daily_expense' => null,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('0.00', $response->json('data.total_daily_income'));
        $this->assertSame('0.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('0.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('0.00', $response->json('data.total_operational_deduction'));
    }

    public function test_changing_persisted_rows_is_reflected_on_next_get(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();
        $project = $this->createProject();

        $entry = $this->seedEntry($project->id, $date, [
            'daily_income' => 100,
            'daily_expense' => 10,
            'administrative_fee' => 12,
            'operational_deduction' => 5,
        ]);

        $first = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();
        $this->assertSame('100.00', $first->json('data.total_daily_income'));
        $this->assertSame('10.00', $first->json('data.total_daily_expenses'));
        $this->assertSame('12.00', $first->json('data.total_administrative_percentage'));
        $this->assertSame('5.00', $first->json('data.total_operational_deduction'));

        $entry->update([
            'daily_income' => 200,
            'daily_expense' => 25,
            'administrative_fee' => 24,
            'operational_deduction' => 8,
        ]);

        $second = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();
        $this->assertSame('200.00', $second->json('data.total_daily_income'));
        $this->assertSame('25.00', $second->json('data.total_daily_expenses'));
        $this->assertSame('24.00', $second->json('data.total_administrative_percentage'));
        $this->assertSame('8.00', $second->json('data.total_operational_deduction'));
    }

    public function test_response_contains_only_the_specified_fields(): void
    {
        $this->actAs('finance');

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $data = $response->json('data');
        $this->assertSame(
            [
                'total_daily_income',
                'total_daily_expenses',
                'total_administrative_percentage',
                'total_operational_deduction',
                'low_stock_items',
                'cash_movement',
                'cash_station_status',
                'cash_station_list',
                'recent_activity',
            ],
            array_keys($data)
        );
        $this->assertIsArray($response->json('data.low_stock_items'));
        $this->assertIsArray($response->json('data.cash_movement.days'));
        $this->assertIsArray($response->json('data.recent_activity'));
        $this->assertIsArray($response->json('data.cash_station_list'));
        $this->assertTrue($response->json('success'));
        $this->assertIsString($response->json('message'));
    }

    public function test_low_stock_items_appear_when_balance_at_or_below_minimum(): void
    {
        $this->actAs('finance');

        $project = $this->createProject();

        $low = InventoryItem::factory()->create([
            'project_id' => $project->id,
            'name' => 'Low stock item',
            'opening_quantity' => 2,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => 2,
            'minimum_stock_level' => 5,
        ]);

        InventoryItem::factory()->create([
            'project_id' => $project->id,
            'name' => 'Healthy stock item',
            'opening_quantity' => 20,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => 20,
            'minimum_stock_level' => 5,
        ]);

        $response = $this->getJson(self::ENDPOINT)->assertOk();
        $items = collect($response->json('data.low_stock_items'));

        $this->assertTrue($items->contains(fn (array $row) => $row['id'] === $low->id));
        $this->assertFalse($items->contains(fn (array $row) => $row['name'] === 'Healthy stock item'));

        $matched = $items->firstWhere('id', $low->id);
        $this->assertSame('2.00', $matched['current_balance']);
        $this->assertSame('5.00', $matched['minimum_stock_level']);
        $this->assertSame($project->id, $matched['project_id']);
    }

    public function test_empty_month_returns_zero_financial_sections(): void
    {
        $this->actAs('finance');

        $response = $this->getJson(self::ENDPOINT.'?journal_date=2026-08-15')->assertOk();

        $this->assertSame([], $response->json('data.cash_movement.days'));
        $this->assertSame('0.00', $response->json('data.cash_movement.monthly_totals.total_monthly_income'));
        $this->assertSame('0.00', $response->json('data.cash_movement.monthly_totals.total_monthly_expenses'));
        $this->assertSame('0.00', $response->json('data.cash_movement.monthly_totals.monthly_net'));
        $this->assertSame(0, $response->json('data.cash_station_status.total_projects'));
        $this->assertSame(0, $response->json('data.cash_station_status.surplus_projects_count'));
        $this->assertSame(0, $response->json('data.cash_station_status.deficit_projects_count'));
        $this->assertSame(0, $response->json('data.cash_station_status.projects_with_administrative_debt_count'));
        $this->assertSame([], $response->json('data.cash_station_list'));
        $this->assertSame([], $response->json('data.recent_activity'));
    }

    public function test_cash_movement_returns_last_7_activity_days_of_month_and_monthly_totals(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // 9 activity days in August 2026 + one day outside month
        for ($day = 1; $day <= 9; $day++) {
            $this->seedEntry($project->id, sprintf('2026-08-%02d', $day), [
                'daily_income' => $day * 10,
                'daily_expense' => $day,
            ]);
        }
        $this->seedEntry($project->id, '2026-07-31', [
            'daily_income' => 999,
            'daily_expense' => 999,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date=2026-08-20')->assertOk();

        $days = $response->json('data.cash_movement.days');
        $this->assertCount(7, $days);
        $this->assertSame('2026-08-09', $days[0]['date']);
        $this->assertSame('90.00', $days[0]['total_income']);
        $this->assertSame('9.00', $days[0]['total_expense']);
        $this->assertSame('2026-08-03', $days[6]['date']);
        $this->assertArrayNotHasKey('net_result', $days[0]);

        // Monthly totals cover all 9 August days, not only the last 7
        $this->assertSame('450.00', $response->json('data.cash_movement.monthly_totals.total_monthly_income'));
        $this->assertSame('45.00', $response->json('data.cash_movement.monthly_totals.total_monthly_expenses'));
        $this->assertSame('405.00', $response->json('data.cash_movement.monthly_totals.monthly_net'));
    }

    public function test_recent_activity_returns_last_5_days_with_net_result(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        for ($day = 1; $day <= 6; $day++) {
            $this->seedEntry($project->id, sprintf('2026-08-%02d', $day), [
                'daily_income' => 100,
                'daily_expense' => 40,
            ]);
        }

        $response = $this->getJson(self::ENDPOINT.'?journal_date=2026-08-10')->assertOk();

        $activity = $response->json('data.recent_activity');
        $this->assertCount(5, $activity);
        $this->assertSame('2026-08-06', $activity[0]['date']);
        $this->assertSame('100.00', $activity[0]['total_income']);
        $this->assertSame('40.00', $activity[0]['total_expenses']);
        $this->assertSame('60.00', $activity[0]['net_result']);
        $this->assertSame('2026-08-02', $activity[4]['date']);
    }

    public function test_cash_station_status_and_list_reuse_cash_station_payload(): void
    {
        $this->actAs('finance');

        $surplus = $this->createProject(['name' => 'Surplus Project']);
        $deficit = $this->createProject(['name' => 'Deficit Project']);

        $this->seedEntry($surplus->id, '2026-08-01', [
            'daily_income' => 1000,
            'daily_expense' => 100,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'accumulated_administrative_debt' => 0,
        ]);
        $this->seedEntry($deficit->id, '2026-08-01', [
            'daily_income' => 50,
            'daily_expense' => 400,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'accumulated_administrative_debt' => 25,
        ]);

        $dashboard = $this->getJson(self::ENDPOINT.'?journal_date=2026-08-15')->assertOk();
        $cashStation = $this->getJson('/api/v1/cash-station?month=8&year=2026')->assertOk();

        $this->assertSame(
            $cashStation->json('data.projects'),
            $dashboard->json('data.cash_station_list'),
        );

        $status = $dashboard->json('data.cash_station_status');
        $this->assertSame(2, $status['total_projects']);
        $this->assertGreaterThanOrEqual(1, $status['surplus_projects_count']);
        $this->assertGreaterThanOrEqual(1, $status['deficit_projects_count']);
        $this->assertSame(1, $status['projects_with_administrative_debt_count']);
    }

    public function test_multiple_projects_on_same_day_are_aggregated(): void
    {
        $this->actAs('finance');
        $a = $this->createProject();
        $b = $this->createProject();

        $this->seedEntry($a->id, '2026-08-05', [
            'daily_income' => 100,
            'daily_expense' => 10,
        ]);
        $this->seedEntry($b->id, '2026-08-05', [
            'daily_income' => 50,
            'daily_expense' => 5,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date=2026-08-05')->assertOk();

        $this->assertSame('150.00', $response->json('data.cash_movement.days.0.total_income'));
        $this->assertSame('15.00', $response->json('data.cash_movement.days.0.total_expense'));
        $this->assertSame('150.00', $response->json('data.recent_activity.0.total_income'));
        $this->assertSame('15.00', $response->json('data.recent_activity.0.total_expenses'));
        $this->assertSame('135.00', $response->json('data.recent_activity.0.net_result'));
    }
}
