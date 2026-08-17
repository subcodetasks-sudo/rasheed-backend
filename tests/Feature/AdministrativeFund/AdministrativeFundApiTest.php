<?php

namespace Tests\Feature\AdministrativeFund;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrativeDebtSettlement\Enums\AdministrativeDebtSettlementStatus;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrativeFundApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/administrative-fund';

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
        $defaults = [
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
            'uncovered_administrative_expense' => 0,
            'project_administration_settled' => 0,
            'outstanding_project_administration' => 0,
        ];

        $payload = array_merge($defaults, $attrs);

        if (! array_key_exists('outstanding_project_administration', $attrs)) {
            $payload['outstanding_project_administration'] = (float) ($payload['uncovered_administrative_expense'] ?? 0);
        }

        return DailyJournalEntry::factory()->create($payload);
    }

    private function findDay(array $payload, string $date): array
    {
        foreach ($payload['days'] as $row) {
            if ($row['date'] === $date) {
                return $row;
            }
        }

        $this->fail("Day {$date} not found in administrative fund response.");
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertForbidden();
    }

    public function test_empty_month_returns_all_days_with_zeros(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('message', __('messages.administrative_fund_fetched_successfully'))
            ->json('data');

        $this->assertSame(8, $data['month']);
        $this->assertSame(2026, $data['year']);
        $this->assertCount(31, $data['days']);
        $this->assertSame('2026-08-01', $data['days'][0]['date']);
        $this->assertSame('Saturday', $data['days'][0]['day']);
        $this->assertRoundedMoneyEquals(0.0, $data['days'][0]['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(0.0, $data['summary']['project_administration']);
        $this->assertRoundedMoneyEquals(0.0, $data['summary']['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(0.0, $data['summary']['cash_fund_contributions']);
        $this->assertRoundedMoneyEquals(0.0, $data['summary']['operational_administration']);
        $this->assertRoundedMoneyEquals(0.0, $data['totals']['net']);
    }

    public function test_project_administration_from_uncovered_administrative_expense(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-10', [
            'uncovered_administrative_expense' => 70,
        ]);

        $other = $this->createProject(['name' => 'Other '.uniqid()]);
        $this->seedEntry($other->id, '2026-08-10', [
            'uncovered_administrative_expense' => 25,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-10');

        $this->assertRoundedMoneyEquals(95.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(-95.0, $day['total_income']);
        $this->assertRoundedMoneyEquals(95.0, $data['summary']['project_administration']);
        $this->assertRoundedMoneyEquals(95.0, $data['totals']['project_administration']);

        $this->assertRoundedMoneyEquals(0.0, $day['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(0.0, $data['summary']['total_administrative_percentage']);
    }

    public function test_total_administrative_percentage_sums_fees_and_never_becomes_project_administration(): void
    {
        $this->actAs('finance');

        foreach ([24, 12, 10] as $fee) {
            $project = $this->createProject(['name' => 'Percentage '.uniqid()]);
            $this->seedEntry($project->id, '2026-08-10', [
                'administrative_fee' => $fee,
                'uncovered_administrative_expense' => 0,
            ]);
        }

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-10');

        $this->assertRoundedMoneyEquals(46.0, $day['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(0.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(46.0, $day['total_income']);
        $this->assertRoundedMoneyEquals(46.0, $day['net']);
        $this->assertRoundedMoneyEquals(46.0, $data['summary']['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(46.0, $data['totals']['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(0.0, $data['totals']['project_administration']);
        $this->assertRoundedMoneyEquals(46.0, $data['totals']['total_income']);
    }

    public function test_both_values_stay_in_their_own_column_on_the_same_day(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-14', [
            'administrative_fee' => 46,
            'administrative_expense' => 100,
            'uncovered_administrative_expense' => 30,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-14');

        $this->assertRoundedMoneyEquals(30.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(46.0, $day['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(16.0, $day['total_income']);
        $this->assertRoundedMoneyEquals(16.0, $day['net']);
    }

    public function test_exempt_project_uncovered_expense_feeds_project_administration_only(): void
    {
        $this->actAs('finance');

        $exempt = $this->createProject([
            'name' => 'Exempt '.uniqid(),
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);
        $this->seedEntry($exempt->id, '2026-08-16', [
            'administrative_fee' => 0,
            'uncovered_administrative_expense' => 55,
        ]);

        $paying = $this->createProject(['name' => 'Paying '.uniqid()]);
        $this->seedEntry($paying->id, '2026-08-16', [
            'administrative_fee' => 24,
            'uncovered_administrative_expense' => 0,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-16');

        $this->assertRoundedMoneyEquals(55.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(24.0, $day['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(-31.0, $day['total_income']);
    }

    public function test_total_income_is_percentage_minus_uncovered_and_contributions_plus_debt_recovery(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-18', [
            'administrative_fee' => 46,
            'uncovered_administrative_expense' => 30,
        ]);

        $this->putJson(self::ENDPOINT.'/2026-08-18', [
            'individual_contributions' => 20,
            'asset_administration' => 5,
        ])->assertOk();

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-18');

        $expectedIncome = 46.0
            - (30.0 + (float) $day['cash_fund_contributions'] + (float) $day['individual_contributions'])
            + (float) $day['debt_recovery'];

        $this->assertMoneyEquals($expectedIncome, $day['total_income']);
        $this->assertRoundedMoneyEquals(30.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(-4.0, $day['total_income']);
        $this->assertRoundedMoneyEquals(5.0, $day['total_expenses']);
        $this->assertRoundedMoneyEquals(-9.0, $day['net']);
        $this->assertRoundedMoneyEquals(46.0, $day['total_administrative_percentage']);
        $this->assertRoundedMoneyEquals(-4.0, $data['summary']['total_income']);
        $this->assertRoundedMoneyEquals(-9.0, $data['summary']['administrative_net']);
    }

    public function test_recalculation_updates_each_column_independently(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();
        $entry = $this->seedEntry($project->id, '2026-08-20', [
            'administrative_fee' => 40,
            'uncovered_administrative_expense' => 15,
        ]);

        $day = $this->findDay(
            $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data'),
            '2026-08-20',
        );
        $this->assertRoundedMoneyEquals(15.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(40.0, $day['total_administrative_percentage']);

        $entry->forceFill([
            'uncovered_administrative_expense' => 65,
            'outstanding_project_administration' => 65,
        ])->save();

        $day = $this->findDay(
            $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data'),
            '2026-08-20',
        );
        $this->assertRoundedMoneyEquals(65.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(-25.0, $day['total_income']);
        $this->assertRoundedMoneyEquals(40.0, $day['total_administrative_percentage']);

        $entry->forceFill(['administrative_fee' => 90])->save();

        $day = $this->findDay(
            $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data'),
            '2026-08-20',
        );
        $this->assertRoundedMoneyEquals(65.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(90.0, $day['total_administrative_percentage']);
    }

    public function test_project_administration_display_is_tip_while_income_uses_daily_uncovered(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-10', [
            'uncovered_administrative_expense' => 100,
            'outstanding_project_administration' => 100,
            'project_administration_settled' => 0,
        ]);
        $this->seedEntry($project->id, '2026-08-11', [
            'uncovered_administrative_expense' => 0,
            'project_administration_settled' => 40,
            'outstanding_project_administration' => 60,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day10 = $this->findDay($data, '2026-08-10');
        $day11 = $this->findDay($data, '2026-08-11');

        $this->assertRoundedMoneyEquals(100.0, $day10['project_administration']);
        $this->assertRoundedMoneyEquals(-100.0, $day10['total_income']);
        $this->assertRoundedMoneyEquals(60.0, $day11['project_administration']);
        $this->assertRoundedMoneyEquals(0.0, $day11['total_income']);
        $this->assertRoundedMoneyEquals(60.0, $data['summary']['project_administration']);
        $this->assertRoundedMoneyEquals(-100.0, $data['summary']['total_income']);
    }

    public function test_month_summary_project_administration_is_month_end_tip_not_sum_of_daily_tips(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-01', [
            'uncovered_administrative_expense' => 80,
            'outstanding_project_administration' => 80,
        ]);
        $this->seedEntry($project->id, '2026-08-15', [
            'uncovered_administrative_expense' => 20,
            'project_administration_settled' => 30,
            'outstanding_project_administration' => 70,
        ]);
        $this->seedEntry($project->id, '2026-08-31', [
            'uncovered_administrative_expense' => 0,
            'project_administration_settled' => 25,
            'outstanding_project_administration' => 45,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');

        $this->assertRoundedMoneyEquals(80.0, $this->findDay($data, '2026-08-01')['project_administration']);
        $this->assertRoundedMoneyEquals(70.0, $this->findDay($data, '2026-08-15')['project_administration']);
        $this->assertRoundedMoneyEquals(45.0, $this->findDay($data, '2026-08-31')['project_administration']);
        $this->assertRoundedMoneyEquals(45.0, $data['summary']['project_administration']);
        $this->assertRoundedMoneyEquals(45.0, $data['totals']['project_administration']);
        $this->assertRoundedMoneyEquals(-100.0, $data['summary']['total_income']);
    }

    private function assertMoneyEquals(float $expected, string|float|null $actual): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.001);
    }

    private function assertRoundedMoneyEquals(float $expected, string|float|null $actual): void
    {
        $this->assertEqualsWithDelta(round($expected), (float) $actual, 0.001);
    }

    public function test_debt_recovery_from_ads_created_at_day(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $settlement = AdministrativeDebtSettlement::query()->create([
            'year' => 2026,
            'month' => 8,
            'project_id' => $project->id,
            'surplus_at_settlement' => 500,
            'recoverable_amount' => 80,
            'allocated_cash_box' => 0,
            'allocated_current_debt' => 50,
            'allocated_carried_debt' => 30,
            'status' => AdministrativeDebtSettlementStatus::Paid,
            'settled_by' => null,
        ]);
        $settlement->forceFill(['created_at' => Carbon::parse('2026-08-12 15:00:00')])->save();

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-12');

        $this->assertRoundedMoneyEquals(80.0, $day['debt_recovery']);
        $this->assertRoundedMoneyEquals(80.0, $day['total_income']);
        $this->assertRoundedMoneyEquals(80.0, $data['summary']['debt_recovery']);
    }

    public function test_put_updates_manuals_and_recalculates(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();
        $this->seedEntry($project->id, '2026-08-05', [
            'administrative_fee' => 100,
            'administrative_debt' => 0,
            'contribution' => 0,
        ]);

        $data = $this->putJson(self::ENDPOINT.'/2026-08-05', [
            'individual_contributions' => 25.5,
            'asset_administration' => 10,
            'notes' => 'test note',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.administrative_fund_updated_successfully'))
            ->json('data');

        $day = $this->findDay($data, '2026-08-05');
        $this->assertRoundedMoneyEquals(0.0, $day['project_administration']);
        $this->assertRoundedMoneyEquals(100.0, $day['total_administrative_percentage']);
        $this->assertSame('25.50', $day['individual_contributions']);
        $this->assertSame('10.00', $day['asset_administration']);
        $this->assertRoundedMoneyEquals(74.5, $day['total_income']);
        $this->assertRoundedMoneyEquals(10.0, $day['total_expenses']);
        $this->assertRoundedMoneyEquals(64.5, $day['net']);
        $this->assertSame('test note', $day['notes']);
        $this->assertRoundedMoneyEquals(74.5, $data['summary']['total_income']);
        $this->assertRoundedMoneyEquals(10.0, $data['summary']['total_expenses']);
        $this->assertRoundedMoneyEquals(64.5, $data['summary']['administrative_net']);
        $this->assertRoundedMoneyEquals(100.0, $data['summary']['total_administrative_percentage']);
    }

    public function test_put_updates_operational_administration_and_recalculates(): void
    {
        $this->actAs('finance');

        $data = $this->putJson(self::ENDPOINT.'/2026-08-05', [
            'operational_administration' => 60,
        ])
            ->assertOk()
            ->json('data');

        $day = $this->findDay($data, '2026-08-05');
        $this->assertSame('60.00', $day['operational_administration']);
        $this->assertRoundedMoneyEquals(60.0, $day['total_expenses']);
        $this->assertRoundedMoneyEquals(-60.0, $day['net']);
        $this->assertRoundedMoneyEquals(60.0, $data['summary']['operational_administration']);
        $this->assertRoundedMoneyEquals(60.0, $data['totals']['operational_administration']);

        $data = $this->putJson(self::ENDPOINT.'/2026-08-05', [
            'operational_administration' => 90,
        ])
            ->assertOk()
            ->json('data');

        $day = $this->findDay($data, '2026-08-05');
        $this->assertSame('90.00', $day['operational_administration']);
        $this->assertRoundedMoneyEquals(90.0, $day['total_expenses']);
        $this->assertRoundedMoneyEquals(-90.0, $day['net']);
    }

    public function test_notes_only_put_recalculates(): void
    {
        $this->actAs('finance');

        $data = $this->putJson(self::ENDPOINT.'/2026-08-01', [
            'notes' => 'notes only',
        ])->assertOk()->json('data');

        $day = $this->findDay($data, '2026-08-01');
        $this->assertSame('notes only', $day['notes']);
        $this->assertSame('0.00', $day['individual_contributions']);
        $this->assertRoundedMoneyEquals(0.0, $day['net']);
    }

    public function test_rejects_calculated_fields(): void
    {
        $this->actAs('finance');

        $this->putJson(self::ENDPOINT.'/2026-08-01', [
            'project_administration' => 99,
            'individual_contributions' => 1,
        ])->assertStatus(422);

        $this->putJson(self::ENDPOINT.'/2026-08-01', [
            'total_administrative_percentage' => 46,
        ])->assertStatus(422);
    }

    public function test_february_days_length(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::ENDPOINT.'?month=2&year=2026')->assertOk()->json('data');
        $this->assertCount(28, $data['days']);

        $leap = $this->getJson(self::ENDPOINT.'?month=2&year=2024')->assertOk()->json('data');
        $this->assertCount(29, $leap['days']);
    }

    public function test_footer_totals_equal_sum_of_days(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();
        $this->seedEntry($project->id, '2026-08-02', [
            'administrative_fee' => 40,
            'administrative_debt' => 0,
            'contribution' => 0,
        ]);
        $this->putJson(self::ENDPOINT.'/2026-08-03', [
            'individual_contributions' => 10,
            'asset_administration' => 5,
        ])->assertOk();

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');

        $sumIncome = 0.0;
        $sumExpenses = 0.0;
        $sumNet = 0.0;
        $sumAdministrativePercentage = 0.0;
        foreach ($data['days'] as $day) {
            $sumIncome += (float) $day['total_income'];
            $sumExpenses += (float) $day['total_expenses'];
            $sumNet += (float) $day['net'];
            $sumAdministrativePercentage += (float) $day['total_administrative_percentage'];
        }

        $this->assertMoneyEquals($sumIncome, $data['totals']['total_income']);
        $this->assertMoneyEquals($sumExpenses, $data['totals']['total_expenses']);
        $this->assertMoneyEquals($sumNet, $data['totals']['net']);
        $this->assertMoneyEquals($sumAdministrativePercentage, $data['totals']['total_administrative_percentage']);
        $this->assertSame($data['summary']['administrative_net'], $data['totals']['net']);

        // fee 40 − individual 10 = 30 (percentage is the base of total_income).
        $this->assertMoneyEquals(40.0, $data['totals']['total_administrative_percentage']);
        $this->assertMoneyEquals(30.0, $data['totals']['total_income']);
    }
}
