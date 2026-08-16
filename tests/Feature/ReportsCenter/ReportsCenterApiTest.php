<?php

namespace Tests\Feature\ReportsCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsCenterApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/reports-center';

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
            'fund_type' => FundType::Variable,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ], $attrs));
    }

    /**
     * Seeds a journal row. Unless the caller explicitly overrides `daily_total`/`fund_balance`,
     * both are auto-computed with the exact DailyJournalCalculationService formula (daily_total =
     * income + contribution − expense − administrative_expense − administrative_fee −
     * operational_deduction; fund_balance = previous day's fund_balance + daily_total), so seeded
     * fixtures stay internally consistent the same way real persisted rows are.
     */
    private function seedEntry(int $projectId, string $date, array $attrs = []): DailyJournalEntry
    {
        $merged = array_merge([
            'project_id' => $projectId,
            'journal_date' => $date,
            'daily_income' => 0,
            'daily_expense' => 0,
            'contribution' => 0,
            'administrative_expense' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
        ], $attrs);

        if (! array_key_exists('daily_total', $attrs)) {
            $merged['daily_total'] = round(
                (float) $merged['daily_income'] + (float) $merged['contribution']
                - (float) $merged['daily_expense'] - (float) $merged['administrative_expense']
                - (float) $merged['administrative_fee'] - (float) $merged['operational_deduction'],
                2
            );
        }

        if (! array_key_exists('fund_balance', $attrs)) {
            $previousFundBalance = (float) (DailyJournalEntry::query()
                ->where('project_id', $projectId)
                ->whereDate('journal_date', '<', $date)
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->value('fund_balance') ?? 0);

            $merged['fund_balance'] = round($previousFundBalance + (float) $merged['daily_total'], 2);
        }

        return DailyJournalEntry::factory()->create($merged);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026')->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026')->assertForbidden();
    }

    public function test_finance_gets_200(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_month_period_validation(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?period_type=month')->assertStatus(422)
            ->assertJsonValidationErrors(['month', 'year']);
    }

    public function test_custom_period_requires_dates_and_order(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?period_type=custom')->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);

        $this->getJson(self::ENDPOINT.'?period_type=custom&start_date=2026-08-20&end_date=2026-08-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_empty_month_returns_zeros_and_full_calendar(): void
    {
        $this->actAs('finance');
        $this->createProject();

        $response = $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026');
        $response->assertOk()
            ->assertJsonCount(31, 'data.charts.income_expense_movement')
            ->assertJsonPath('data.summary.total_income', '0.00')
            ->assertJsonPath('data.summary.total_expense', '0.00')
            ->assertJsonPath('data.summary.administrative_percentage', '0.00')
            ->assertJsonPath('data.summary.operational_deduction', '0.00')
            ->assertJsonPath('data.summary.net_cash_funds', '0.00')
            ->assertJsonPath('data.totals.total_net', '0.00');

        $this->assertCount(1, $response->json('data.projects'));
        $this->assertSame('0.00', $response->json('data.projects.0.debt'));
    }

    public function test_single_day_custom_period(): void
    {
        $this->actAs('finance');
        $project = $this->createProject(['fund_type' => FundType::Fixed]);

        $this->seedEntry($project->id, '2026-08-05', [
            'daily_income' => 1000,
            'daily_expense' => 100,
            'administrative_fee' => 120,
            'administrative_debt' => 20,
            'contribution' => 10,
            'operational_deduction' => 50,
            'accumulated_administrative_debt' => 75,
        ]);

        $response = $this->getJson(
            self::ENDPOINT.'?period_type=custom&start_date=2026-08-05&end_date=2026-08-05'
        );
        $response->assertOk()
            ->assertJsonCount(1, 'data.charts.income_expense_movement')
            ->assertJsonPath('data.period.period_type', 'custom')
            ->assertJsonPath('data.period.start_date', '2026-08-05')
            ->assertJsonPath('data.period.end_date', '2026-08-05');

        // collected admin = 120 - 20 + 10 = 110
        // net = 1000 - 100 - 110 - 50 = 740
        $this->assertSame('1000.00', $response->json('data.summary.total_income'));
        $this->assertSame('100.00', $response->json('data.summary.total_expense'));
        $this->assertSame('110.00', $response->json('data.summary.administrative_percentage'));
        $this->assertSame('50.00', $response->json('data.summary.operational_deduction'));
        $this->assertSame('740.00', $response->json('data.summary.net_cash_funds'));

        $row = $response->json('data.projects.0');
        $this->assertSame('fixed', $row['fund_type']);
        $this->assertSame('740.00', $row['net']);
        $this->assertSame('75.00', $row['debt']);

        $day = $response->json('data.charts.income_expense_movement.0');
        $this->assertSame('2026-08-05', $day['date']);
        $this->assertSame('740.00', $day['daily_net_movement']);

        $this->assertSame('100.00', $response->json('data.charts.expense_distribution.direct_expenses'));
    }

    public function test_cross_month_custom_range_and_month_isolation(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-31', [
            'daily_income' => 500,
            'daily_expense' => 50,
            'administrative_fee' => 60,
            'operational_deduction' => 10,
            'accumulated_administrative_debt' => 5,
        ]);
        $this->seedEntry($project->id, '2026-08-01', [
            'daily_income' => 200,
            'daily_expense' => 20,
            'administrative_fee' => 24,
            'operational_deduction' => 5,
            'accumulated_administrative_debt' => 8,
        ]);
        $this->seedEntry($project->id, '2026-08-15', [
            'daily_income' => 100,
            'daily_expense' => 0,
            'administrative_fee' => 12,
            'operational_deduction' => 0,
            'accumulated_administrative_debt' => 8,
        ]);

        $custom = $this->getJson(
            self::ENDPOINT.'?period_type=custom&start_date=2026-07-31&end_date=2026-08-01'
        );
        $custom->assertOk()
            ->assertJsonCount(2, 'data.charts.income_expense_movement')
            ->assertJsonPath('data.summary.total_income', '700.00')
            ->assertJsonPath('data.summary.total_expense', '70.00')
            ->assertJsonPath('data.summary.administrative_percentage', '84.00')
            ->assertJsonPath('data.summary.operational_deduction', '15.00')
            ->assertJsonPath('data.summary.net_cash_funds', '531.00');

        $august = $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026');
        $august->assertOk()
            ->assertJsonPath('data.period.month', 8)
            ->assertJsonPath('data.period.year', 2026)
            ->assertJsonPath('data.summary.total_income', '300.00')
            ->assertJsonPath('data.summary.total_expense', '20.00')
            ->assertJsonPath('data.summary.administrative_percentage', '36.00')
            ->assertJsonPath('data.summary.operational_deduction', '5.00')
            ->assertJsonPath('data.summary.net_cash_funds', '239.00');
    }

    public function test_expense_ignores_administrative_expense_field(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-10', [
            'daily_income' => 0,
            'daily_expense' => 40,
            'administrative_expense' => 999,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.summary.total_expense', '40.00')
            ->assertJsonPath('data.charts.expense_distribution.direct_expenses', '40.00');
    }

    public function test_admin_exempt_and_zero_operational(): void
    {
        $this->actAs('finance');
        $project = $this->createProject(['administrative_exempt' => true]);

        // A real exempt entry always has fee=0 (AdministrativeDeductionService zeroes it at write
        // time); the fee column here only exercises defensive exemption handling, so daily_total
        // is pinned to the fee-free outcome a real exempt entry would actually have.
        $this->seedEntry($project->id, '2026-08-12', [
            'daily_income' => 500,
            'daily_expense' => 0,
            'administrative_fee' => 999,
            'administrative_debt' => 100,
            'operational_deduction' => 0,
            'accumulated_administrative_debt' => 0,
            'daily_total' => 500,
            'fund_balance' => 500,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.summary.administrative_percentage', '0.00')
            ->assertJsonPath('data.summary.operational_deduction', '0.00')
            ->assertJsonPath('data.summary.net_cash_funds', '500.00')
            ->assertJsonPath('data.projects.0.debt', '0.00');
    }

    public function test_matches_cash_station_aggregates_for_same_month_window(): void
    {
        $this->actAs('finance');
        $project = $this->createProject(['fund_type' => FundType::Fixed]);

        $this->seedEntry($project->id, '2026-08-03', [
            'daily_income' => 800,
            'daily_expense' => 50,
            'administrative_fee' => 96,
            'administrative_debt' => 16,
            'contribution' => 5,
            'operational_deduction' => 40,
            'accumulated_administrative_debt' => 22,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026');
        $response->assertOk();

        $cs = app(BuildCashStationAction::class);
        $aggregates = $cs->monthlyAggregatesByProject(
            [(int) $project->id],
            '2026-08-01',
            '2026-08-31',
        );
        $aggregate = $aggregates[(int) $project->id];
        $net = $cs->monthlyTotalFromAggregate($aggregate);

        $this->assertSame(
            number_format((float) $aggregate->monthly_revenue, 2, '.', ''),
            $response->json('data.projects.0.income'),
        );
        $this->assertSame(
            number_format((float) $aggregate->monthly_expenses, 2, '.', ''),
            $response->json('data.projects.0.expense'),
        );
        $this->assertSame(
            number_format((float) $aggregate->administrative_percentage, 2, '.', ''),
            $response->json('data.projects.0.administrative'),
        );
        $this->assertSame(
            number_format((float) $aggregate->operational_deduction, 2, '.', ''),
            $response->json('data.projects.0.operational'),
        );
        $this->assertSame(
            number_format($net, 2, '.', ''),
            $response->json('data.projects.0.net'),
        );
        $this->assertSame(
            number_format($net, 2, '.', ''),
            $response->json('data.summary.net_cash_funds'),
        );
    }

    public function test_response_shape(): void
    {
        $this->actAs('finance');

        $this->getJson(self::ENDPOINT.'?period_type=month&month=8&year=2026')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period' => ['period_type', 'start_date', 'end_date', 'month', 'year'],
                    'summary' => [
                        'total_income',
                        'total_expense',
                        'administrative_percentage',
                        'operational_deduction',
                        'net_cash_funds',
                    ],
                    'charts' => [
                        'income_expense_movement',
                        'expense_distribution' => [
                            'direct_expenses',
                            'administrative_percentage',
                            'operational_deduction',
                        ],
                        'project_comparison',
                    ],
                    'projects',
                    'totals' => [
                        'total_income',
                        'total_expense',
                        'total_administrative',
                        'total_operational',
                        'total_net',
                    ],
                ],
            ]);
    }
}
