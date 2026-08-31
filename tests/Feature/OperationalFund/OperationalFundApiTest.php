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

    /**
     * PUT the day with a single expense line item totalling $amount (0 clears the day).
     */
    private function putExpense(string $date, float $amount, string $description = 'مصروف تشغيلي')
    {
        $expenses = $amount > 0
            ? [['amount' => $amount, 'description' => $description]]
            : [];

        return $this->putJson(self::MONTH.'/'.$date, ['expenses' => $expenses]);
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

    public function test_day_expected_income_equals_employee_total(): void
    {
        $this->actAs('finance');
        $this->createFixedProject(154);

        // Expected operational income is the whole employee-cost budget outright — the fixed
        // project's 154 is a carve-out of that budget (reducing the relative pool elsewhere), not
        // an addition on top of it.
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;
        $expected = number_format($base, 0, '.', '');

        $data = $this->getJson(self::DAY.'?date=2026-08-05')
            ->assertOk()
            ->assertJsonPath('message', __('messages.operational_fund_day_fetched_successfully'))
            ->json('data');

        $this->assertSame('2026-08-05', $data['date']);
        $this->assertSame($expected, $data['expected_operational_income']);
        $this->assertSame('0', $data['operational_expense']);
        $this->assertSame($expected, $data['daily_operational_net']);
        $this->assertSame('operational_surplus', $data['day_status']);
        $this->assertSame([], $data['expenses']);
    }

    public function test_put_expense_updates_net_and_returns_month(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        $data = $this->putExpense('2026-08-01', $base + 100)
            ->assertOk()
            ->assertJsonPath('message', __('messages.operational_fund_updated_successfully'))
            ->json('data');

        $day = $this->findDay($data, '2026-08-01');
        $this->assertSame(number_format($base, 0, '.', ''), $day['operational_income']);
        $this->assertSame(number_format($base + 100, 0, '.', ''), $day['operational_expense']);
        $this->assertSame('-100', $day['daily_net']);
        $this->assertSame('operational_deficit', $day['day_status']);
        $this->assertSame('not_covered', $day['deficit_coverage_status']);
    }

    public function test_coverage_covered_when_prior_surplus_exists(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        // Day 1: expense 0 → surplus = base
        $this->putExpense('2026-08-01', 0)->assertOk();

        // Day 2: expense = base + 50 → deficit 50, covered by day-1 surplus
        $data = $this->putExpense('2026-08-02', $base + 50)->assertOk()->json('data');

        $day2 = $this->findDay($data, '2026-08-02');
        $this->assertSame('operational_deficit', $day2['day_status']);
        $this->assertSame('covered', $day2['deficit_coverage_status']);
    }

    public function test_editing_earlier_day_recalculates_later_coverage(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        $this->putExpense('2026-08-01', 0)->assertOk();
        $this->putExpense('2026-08-02', $base + 50)->assertOk();

        // Exhaust day-1 surplus by setting expense = base (net 0) → day 2 not covered
        $data = $this->putExpense('2026-08-01', $base)->assertOk()->json('data');

        $day2 = $this->findDay($data, '2026-08-02');
        $this->assertSame('not_covered', $day2['deficit_coverage_status']);
    }

    public function test_month_isolation_no_cross_month_surplus(): void
    {
        $this->actAs('finance');
        $base = ResolveEffectiveOperationalDeductionAction::DEFAULT_TOTAL;

        // July surplus
        $this->putExpense('2026-07-31', 0)->assertOk();

        // August deficit without August surplus
        $data = $this->putExpense('2026-08-01', $base + 10)->assertOk()->json('data');

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
        $this->putExpense('2026-08-03', 100)->assertOk();

        $data = $this->getJson(self::MONTH.'?month=8&year=2026')->assertOk()->json('data');

        $sumIncome = 0.0;
        $sumExpense = 0.0;
        foreach ($data['days'] as $day) {
            $sumIncome += (float) $day['operational_income'];
            $sumExpense += (float) $day['operational_expense'];
        }

        $this->assertSame(
            $data['summary']['total_monthly_operational_income'],
            number_format($sumIncome, 0, '.', ''),
        );
        $this->assertSame(
            $data['summary']['total_monthly_operational_expense'],
            number_format($sumExpense, 0, '.', ''),
        );
        $this->assertSame(
            $data['summary']['monthly_operational_net'],
            number_format($sumIncome - $sumExpense, 0, '.', ''),
        );
    }

    public function test_rejects_non_editable_put_fields(): void
    {
        $this->actAs('finance');

        // operational_expense is now derived from the line items and no longer accepted.
        $this->putJson(self::MONTH.'/2026-08-01', [
            'expenses' => [],
            'operational_expense' => 10,
        ])->assertStatus(422);

        $this->putJson(self::MONTH.'/2026-08-01', [
            'expenses' => [],
            'expected_operational_income' => 999,
        ])->assertStatus(422);
    }

    public function test_put_expenses_sets_operational_expense_to_their_sum(): void
    {
        $this->actAs('finance');

        $data = $this->putJson(self::MONTH.'/2026-08-10', [
            'expenses' => [
                ['amount' => 300, 'description' => 'رواتب عمال نظافة'],
                ['amount' => 150.5, 'description' => 'صيانة مكيّف'],
            ],
        ])->assertOk()->json('data');

        $day = $this->findDay($data, '2026-08-10');
        $this->assertSame('451', $day['operational_expense']);
        $this->assertCount(2, $day['expenses']);
        $this->assertSame('300', $day['expenses'][0]['amount']);
        $this->assertSame('رواتب عمال نظافة', $day['expenses'][0]['description']);

        // Flat month-level breakdown table (جدول أسفل الصفحة).
        $items = collect($data['expense_items'])->where('date', '2026-08-10')->values();
        $this->assertCount(2, $items);
        $this->assertSame('151', $items[1]['amount']);
        $this->assertSame('صيانة مكيّف', $items[1]['description']);

        // Day endpoint exposes the same line items.
        $dayPayload = $this->getJson(self::DAY.'?date=2026-08-10')->assertOk()->json('data');
        $this->assertSame('451', $dayPayload['operational_expense']);
        $this->assertCount(2, $dayPayload['expenses']);
    }

    public function test_put_expenses_replaces_previous_line_items(): void
    {
        $this->actAs('finance');

        $this->putJson(self::MONTH.'/2026-08-11', [
            'expenses' => [
                ['amount' => 300, 'description' => 'رواتب'],
                ['amount' => 200, 'description' => 'وقود'],
            ],
        ])->assertOk();

        $data = $this->putJson(self::MONTH.'/2026-08-11', [
            'expenses' => [
                ['amount' => 120, 'description' => 'قرطاسية'],
            ],
        ])->assertOk()->json('data');

        $day = $this->findDay($data, '2026-08-11');
        $this->assertSame('120', $day['operational_expense']);
        $this->assertCount(1, $day['expenses']);
        $this->assertCount(1, collect($data['expense_items'])->where('date', '2026-08-11'));
    }

    public function test_expense_item_requires_description_and_positive_amount(): void
    {
        $this->actAs('finance');

        $this->putJson(self::MONTH.'/2026-08-12', [
            'expenses' => [['amount' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('expenses.0.description');

        $this->putJson(self::MONTH.'/2026-08-12', [
            'expenses' => [['amount' => 0, 'description' => 'صفر']],
        ])->assertStatus(422)->assertJsonValidationErrors('expenses.0.amount');
    }

    public function test_admin_fund_operational_administration_is_independent_of_operational_fund(): void
    {
        $this->actAs('finance');

        $this->putExpense('2026-08-10', 77.5)->assertOk();

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
        $this->assertSame('0.00', $day['operational_administration']);
        $this->assertSame('0', $day['total_expenses']);

        $this->putJson('/api/v1/administrative-fund/2026-08-10', [
            'operational_administration' => 40,
        ])->assertOk();

        $operationalFundDay = $this->getJson(self::DAY.'?date=2026-08-10')
            ->assertOk()
            ->json('data');

        $this->assertSame('78', $operationalFundDay['operational_expense']);
    }

    public function test_clear_expense_with_null(): void
    {
        $this->actAs('finance');

        $this->putExpense('2026-08-04', 50)->assertOk();
        $data = $this->putJson(self::MONTH.'/2026-08-04', ['expenses' => []])
            ->assertOk()
            ->json('data');

        $day = $this->findDay($data, '2026-08-04');
        $this->assertSame('0', $day['operational_expense']);
        $this->assertSame([], $day['expenses']);
        $this->assertCount(0, collect($data['expense_items'])->where('date', '2026-08-04'));
    }
}
