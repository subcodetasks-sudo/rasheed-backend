<?php

namespace Tests\Feature\OperationalFund;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationalFundApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '/api/v1/operational-fund';

    private const DAY = '/api/v1/operational-fund/day';

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createFixedProject(float $amount): Project
    {
        return Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => $amount,
            'administrative_exempt' => false,
        ]);
    }

    private function findDay(array $payload, string $date): array
    {
        foreach ($payload['days'] as $row) {
            if ($row['date'] === $date) {
                return $row;
            }
        }

        $this->fail("Day {$date} not found.");
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::MONTH.'?month=8&year=2026')->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::MONTH.'?month=8&year=2026')->assertForbidden();
    }

    public function test_day_expected_income_is_base_plus_fixed(): void
    {
        $this->actAs('finance');
        $this->createFixedProject(154);

        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;
        $expected = number_format($base + 154, 2, '.', '');

        $data = $this->getJson(self::DAY.'?date=2026-08-05')
            ->assertOk()
            ->assertJsonPath('message', __('messages.operational_fund_day_fetched_successfully'))
            ->json('data');

        $this->assertSame('2026-08-05', $data['date']);
        $this->assertSame($expected, $data['expected_operational_income']);
        $this->assertSame('0.00', $data['operational_expense']);
        $this->assertSame($expected, $data['daily_operational_net']);
        $this->assertSame('operational_surplus', $data['day_status']);
    }

    public function test_put_expense_updates_net_and_returns_month(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        $data = $this->putJson(self::MONTH.'/2026-08-01', [
            'operational_expense' => $base + 100,
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.operational_fund_updated_successfully'))
            ->json('data');

        $day = $this->findDay($data, '2026-08-01');
        $this->assertSame(number_format($base, 2, '.', ''), $day['operational_income']);
        $this->assertSame(number_format($base + 100, 2, '.', ''), $day['operational_expense']);
        $this->assertSame('-100.00', $day['daily_net']);
        $this->assertSame('operational_deficit', $day['day_status']);
        $this->assertSame('not_covered', $day['deficit_coverage_status']);
    }

    public function test_coverage_covered_when_prior_surplus_exists(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        // Day 1: expense 0 → surplus = base
        $this->putJson(self::MONTH.'/2026-08-01', ['operational_expense' => 0])->assertOk();

        // Day 2: expense = base + 50 → deficit 50, covered by day-1 surplus
        $data = $this->putJson(self::MONTH.'/2026-08-02', [
            'operational_expense' => $base + 50,
        ])->assertOk()->json('data');

        $day2 = $this->findDay($data, '2026-08-02');
        $this->assertSame('operational_deficit', $day2['day_status']);
        $this->assertSame('covered', $day2['deficit_coverage_status']);
    }

    public function test_editing_earlier_day_recalculates_later_coverage(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        $this->putJson(self::MONTH.'/2026-08-01', ['operational_expense' => 0])->assertOk();
        $this->putJson(self::MONTH.'/2026-08-02', [
            'operational_expense' => $base + 50,
        ])->assertOk();

        // Exhaust day-1 surplus by setting expense = base (net 0) → day 2 not covered
        $data = $this->putJson(self::MONTH.'/2026-08-01', [
            'operational_expense' => $base,
        ])->assertOk()->json('data');

        $day2 = $this->findDay($data, '2026-08-02');
        $this->assertSame('not_covered', $day2['deficit_coverage_status']);
    }

    public function test_month_isolation_no_cross_month_surplus(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        // July surplus
        $this->putJson(self::MONTH.'/2026-07-31', ['operational_expense' => 0])->assertOk();

        // August deficit without August surplus
        $data = $this->putJson(self::MONTH.'/2026-08-01', [
            'operational_expense' => $base + 10,
        ])->assertOk()->json('data');

        $day = $this->findDay($data, '2026-08-01');
        $this->assertSame('not_covered', $day['deficit_coverage_status']);
        $this->assertSame(8, $data['month']);
    }

    public function test_february_day_counts(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::MONTH.'?month=2&year=2026')->assertOk()->json('data');
        $this->assertCount(28, $data['days']);

        $leap = $this->getJson(self::MONTH.'?month=2&year=2024')->assertOk()->json('data');
        $this->assertCount(29, $leap['days']);
    }

    public function test_month_summary_equals_sum_of_days(): void
    {
        $this->actAs('finance');
        $this->putJson(self::MONTH.'/2026-08-03', ['operational_expense' => 100])->assertOk();

        $data = $this->getJson(self::MONTH.'?month=8&year=2026')->assertOk()->json('data');

        $sumIncome = 0.0;
        $sumExpense = 0.0;
        foreach ($data['days'] as $day) {
            $sumIncome += (float) $day['operational_income'];
            $sumExpense += (float) $day['operational_expense'];
        }

        $this->assertSame(
            $data['summary']['total_monthly_operational_income'],
            number_format($sumIncome, 2, '.', ''),
        );
        $this->assertSame(
            $data['summary']['total_monthly_operational_expense'],
            number_format($sumExpense, 2, '.', ''),
        );
        $this->assertSame(
            $data['summary']['monthly_operational_net'],
            number_format($sumIncome - $sumExpense, 2, '.', ''),
        );
    }

    public function test_rejects_non_editable_put_fields(): void
    {
        $this->actAs('finance');

        $this->putJson(self::MONTH.'/2026-08-01', [
            'operational_expense' => 10,
            'expected_operational_income' => 999,
        ])->assertStatus(422);
    }

    public function test_admin_fund_reads_operational_expense(): void
    {
        $this->actAs('finance');

        $this->putJson(self::MONTH.'/2026-08-10', [
            'operational_expense' => 77.5,
        ])->assertOk();

        $admin = $this->getJson('/api/v1/administrative-fund?month=8&year=2026')
            ->assertOk()
            ->json('data');

        $day = null;
        foreach ($admin['days'] as $row) {
            if ($row['date'] === '2026-08-10') {
                $day = $row;
                break;
            }
        }

        $this->assertNotNull($day);
        $this->assertSame('77.50', $day['operational_administration']);
        $this->assertSame('77.50', $day['total_expenses']);
    }

    public function test_clear_expense_with_null(): void
    {
        $this->actAs('finance');

        $this->putJson(self::MONTH.'/2026-08-04', ['operational_expense' => 50])->assertOk();
        $data = $this->putJson(self::MONTH.'/2026-08-04', ['operational_expense' => null])
            ->assertOk()
            ->json('data');

        $day = $this->findDay($data, '2026-08-04');
        $this->assertSame('0.00', $day['operational_expense']);
    }
}
