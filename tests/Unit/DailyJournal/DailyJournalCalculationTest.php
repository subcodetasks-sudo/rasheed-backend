<?php

namespace Tests\Unit\DailyJournal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DailyJournal\Actions\UpdateDailyJournalReportsAction;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\DailyJournalCalculationService;
use Modules\DailyJournal\Workflows\SaveDailyJournalWorkflow;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Settings\Models\SystemGeneralSetting;
use Modules\Settings\Services\SettingService;
use RuntimeException;
use Tests\TestCase;

class DailyJournalCalculationTest extends TestCase
{
    use RefreshDatabase;

    private DailyJournalCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DailyJournalCalculationService::class);
    }

    public function test_daily_total_equation(): void
    {
        $this->assertEquals(
            800.0,
            $this->service->calculateDailyTotal(
                income: 1000,
                contribution: 50,
                expense: 100,
                administrativeFee: 120,
                operationalDeduction: 30,
            )
        );
    }

    public function test_fund_balance_equation(): void
    {
        $this->assertEquals(150.0, $this->service->calculateFundBalance(100, 50));
        $this->assertEquals(-60.0, $this->service->calculateFundBalance(40, -100));
    }

    public function test_administrative_expense_coverage_cases(): void
    {
        $full = $this->service->calculateAdministrativeExpenseCoverage(500, 100);
        $this->assertSame(['covered' => 100.0, 'uncovered' => 0.0, 'fund_balance' => 400.0], $full);

        $partial = $this->service->calculateAdministrativeExpenseCoverage(60, 100);
        $this->assertSame(['covered' => 60.0, 'uncovered' => 40.0, 'fund_balance' => 0.0], $partial);

        $none = $this->service->calculateAdministrativeExpenseCoverage(-12, 80);
        $this->assertSame(['covered' => 0.0, 'uncovered' => 80.0, 'fund_balance' => -12.0], $none);
    }

    public function test_administrative_debt_case1_only(): void
    {
        // Case 1: deficit covered by full same-day fee
        $this->assertEquals(
            24.0,
            $this->service->calculateAdministrativeDebt(
                fundBalance: -100,
                administrativeFee: 24,
            )
        );

        // Case 1 capped by deficit magnitude
        $this->assertEquals(
            10.0,
            $this->service->calculateAdministrativeDebt(
                fundBalance: -10,
                administrativeFee: 24,
            )
        );

        // Surplus day → no debt
        $this->assertEquals(
            0.0,
            $this->service->calculateAdministrativeDebt(
                fundBalance: 370,
                administrativeFee: 50,
            )
        );

        // Negative balance alone with zero fee → no debt
        $this->assertEquals(
            0.0,
            $this->service->calculateAdministrativeDebt(
                fundBalance: -60,
                administrativeFee: 0,
            )
        );

        // Positive balance → no debt
        $this->assertEquals(
            0.0,
            $this->service->calculateAdministrativeDebt(
                fundBalance: 25,
                administrativeFee: 50,
            )
        );
    }

    public function test_signed_fund_balance_scenarios(): void
    {
        // 1000 + (-300) = 700
        $this->assertEquals(700.0, $this->service->calculateFundBalance(1000, -300));

        // 300 + (-900) = -600
        $this->assertEquals(-600.0, $this->service->calculateFundBalance(300, -900));

        // -400 + 250 = -150
        $this->assertEquals(-150.0, $this->service->calculateFundBalance(-400, 250));

        // -400 + 800 = 400
        $this->assertEquals(400.0, $this->service->calculateFundBalance(-400, 800));
    }

    public function test_accumulated_debt_equation(): void
    {
        $this->assertEquals(70.0, $this->service->calculateAccumulatedAdministrativeDebt(10, 60));
        $this->assertEquals(10.0, $this->service->calculateAccumulatedAdministrativeDebt(10, 0));
    }

    public function test_administrative_fee_uses_project_percentage(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 15,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $entry = new DailyJournalEntry([
            'project_id' => $project->id,
            'daily_income' => 1000,
        ]);
        $entry->setRelation('project', $project);

        $entries = $this->service->applyAdministrativeFees(collect([$entry]));

        $this->assertEquals(150.0, (float) $entries->first()->administrative_fee);
    }

    public function test_administrative_debt_derives_from_each_projects_own_fee_percentage(): void
    {
        // If calculations silently used the global setting (50%), both projects would
        // produce fee/debt 500 — prove each project's own percentage drives debt instead.
        SystemGeneralSetting::singleton()->update(['admin_fee_percentage' => 50]);

        $atTen = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 10,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);
        $atTwenty = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 20,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $entries = collect([
            tap(new DailyJournalEntry(['project_id' => $atTen->id, 'daily_income' => 1000]), fn ($e) => $e->setRelation('project', $atTen)),
            tap(new DailyJournalEntry(['project_id' => $atTwenty->id, 'daily_income' => 1000]), fn ($e) => $e->setRelation('project', $atTwenty)),
        ]);

        $withFees = $this->service->applyAdministrativeFees($entries)->keyBy('project_id');

        $this->assertEquals(100.0, (float) $withFees[$atTen->id]->administrative_fee);
        $this->assertEquals(200.0, (float) $withFees[$atTwenty->id]->administrative_fee);

        foreach ($withFees as $entry) {
            $entry->contribution = 0;
            $entry->administrative_expense = 0;
            $entry->fund_balance = -1000;
        }

        $withDebt = $this->service->applyAdministrativeDebt($withFees->values())->keyBy('project_id');

        $this->assertEquals(100.0, (float) $withDebt[$atTen->id]->administrative_debt);
        $this->assertEquals(200.0, (float) $withDebt[$atTwenty->id]->administrative_debt);
    }

    public function test_operational_deduction_relative_fixed_exempt(): void
    {
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $relative = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);
        $fixed = Project::factory()->fixedDeduction(154)->create(['status' => ProjectStatus::Active]);
        $exempt = Project::factory()->exempt()->create(['status' => ProjectStatus::Active]);

        $entries = collect([
            tap(new DailyJournalEntry(['project_id' => $relative->id, 'daily_income' => 1000]), fn ($e) => $e->setRelation('project', $relative)),
            tap(new DailyJournalEntry(['project_id' => $fixed->id, 'daily_income' => 500]), fn ($e) => $e->setRelation('project', $fixed)),
            tap(new DailyJournalEntry(['project_id' => $exempt->id, 'daily_income' => 500]), fn ($e) => $e->setRelation('project', $exempt)),
        ]);

        $result = $this->service->applyOperationalDeductions($entries)->keyBy('project_id');

        $this->assertEquals(1081.0, (float) $result[$relative->id]->operational_deduction);
        $this->assertEquals(154.0, (float) $result[$fixed->id]->operational_deduction);
        $this->assertEquals(0.0, (float) $result[$exempt->id]->operational_deduction);
    }

    public function test_operational_deduction_forced_zero_when_income_and_expense_both_zero(): void
    {
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        // Fixed type normally charges its flat amount regardless of income — must be
        // suppressed when the entry has zero income AND zero expense.
        $fixed = Project::factory()->fixedDeduction(154)->create(['status' => ProjectStatus::Active]);

        $zeroActivity = tap(
            new DailyJournalEntry(['project_id' => $fixed->id, 'daily_income' => 0, 'daily_expense' => 0]),
            fn ($e) => $e->setRelation('project', $fixed)
        );
        $result = $this->service->applyOperationalDeductions(collect([$zeroActivity]))->keyBy('project_id');
        $this->assertEquals(0.0, (float) $result[$fixed->id]->operational_deduction);

        // Regression: a non-zero-activity Fixed-type entry must still get its flat deduction.
        $withExpenseOnly = tap(
            new DailyJournalEntry(['project_id' => $fixed->id, 'daily_income' => 0, 'daily_expense' => 20]),
            fn ($e) => $e->setRelation('project', $fixed)
        );
        $result = $this->service->applyOperationalDeductions(collect([$withExpenseOnly]))->keyBy('project_id');
        $this->assertEquals(154.0, (float) $result[$fixed->id]->operational_deduction);
    }

    public function test_fund_balance_and_administrative_debt_pipeline(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);
        $date = now()->startOfDay();

        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => $date->copy()->subDay()->toDateString(),
            'fund_balance' => 40,
            'accumulated_administrative_debt' => 10,
        ]);

        $entry = DailyJournalEntry::factory()->make([
            'project_id' => $project->id,
            'journal_date' => $date->toDateString(),
            'daily_total' => -100,
            'administrative_fee' => 24,
            'administrative_expense' => 0,
        ]);

        $previous = $this->service->previousBalances([$project->id], $date);
        $entries = $this->service->applyFundBalances(collect([$entry]), $previous);
        $this->assertEquals(-60.0, (float) $entries->first()->fund_balance);

        $entries = $this->service->applyAdministrativeExpenseCoverage($entries);
        $entries = $this->service->applyAdministrativeDebt($entries);
        $this->assertEquals(-36.0, (float) $entries->first()->fund_balance);
        $this->assertEquals(24.0, (float) $entries->first()->administrative_debt);

        $entries = $this->service->applyAccumulatedAdministrativeDebt($entries, $previous);
        $this->assertEquals(34.0, (float) $entries->first()->accumulated_administrative_debt);
    }

    public function test_administrative_expense_coverage_skipped_when_income_and_expense_both_zero(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $previousBalances = [
            $project->id => [
                'fund_balance' => 500,
                'accumulated_administrative_debt' => 0,
                'outstanding_project_administration' => 30,
            ],
        ];

        // Zero-activity day with a real inventory-sourced administrative expense and same-day surplus:
        // the expense must still be recorded/uncovered/rolled into outstanding, but fund_balance must
        // NOT be reduced by it.
        $entry = new DailyJournalEntry([
            'project_id' => $project->id,
            'daily_income' => 0,
            'daily_expense' => 0,
            'fund_balance' => 500,
            'administrative_expense' => 100,
        ]);

        $result = $this->service->applyAdministrativeExpenseCoverage(collect([$entry]), $previousBalances)->first();

        $this->assertEquals(500.0, (float) $result->fund_balance);
        $this->assertEquals(100.0, (float) $result->uncovered_administrative_expense);
        $this->assertEquals(0.0, (float) $result->project_administration_settled);
        $this->assertEquals(130.0, (float) $result->outstanding_project_administration);
    }

    public function test_administrative_expense_coverage_unaffected_when_income_or_expense_present(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $previousBalances = [
            $project->id => [
                'fund_balance' => 500,
                'accumulated_administrative_debt' => 0,
                'outstanding_project_administration' => 30,
            ],
        ];

        // Same numbers as the zero-activity case above, but with non-zero income — coverage/settlement
        // must run exactly as before (regression guard for the "don't change old logic" constraint).
        $entry = new DailyJournalEntry([
            'project_id' => $project->id,
            'daily_income' => 50,
            'daily_expense' => 0,
            'fund_balance' => 500,
            'administrative_expense' => 100,
        ]);

        $result = $this->service->applyAdministrativeExpenseCoverage(collect([$entry]), $previousBalances)->first();

        $this->assertEquals(370.0, (float) $result->fund_balance);
        $this->assertEquals(0.0, (float) $result->uncovered_administrative_expense);
        $this->assertEquals(30.0, (float) $result->project_administration_settled);
        $this->assertEquals(0.0, (float) $result->outstanding_project_administration);
    }

    public function test_contribution_adds_to_administrative_debt_from_pre_contribution_base(): void
    {
        // Base pinned to fund_balance − contribution (Pass-1 balance).
        // fund=-1070 with contribution 30 → pre-contribution = -1100; fee 100 → base 100; debt 130
        $withThirty = new DailyJournalEntry([
            'contribution' => 30,
            'fund_balance' => -1070,
            'administrative_fee' => 100,
            'administrative_expense' => 0,
        ]);
        $this->assertEquals(
            130.0,
            (float) $this->service->applyAdministrativeDebt(collect([$withThirty]))->first()->administrative_debt
        );
        $this->assertEquals(-970.0, (float) $withThirty->fund_balance);

        // Re-save contribution 40 against same day math → fund=-1060; base still 100; debt 140 (not 170)
        $withForty = new DailyJournalEntry([
            'contribution' => 40,
            'fund_balance' => -1060,
            'administrative_fee' => 100,
            'administrative_expense' => 0,
        ]);
        $this->assertEquals(
            140.0,
            (float) $this->service->applyAdministrativeDebt(collect([$withForty]))->first()->administrative_debt
        );
        $this->assertEquals(-960.0, (float) $withForty->fund_balance);

        // Fee 0 (exempt): contribution alone is the debt; fund unchanged
        $exempt = new DailyJournalEntry([
            'contribution' => 100,
            'fund_balance' => -50,
            'administrative_fee' => 0,
            'administrative_expense' => 0,
        ]);
        $this->assertEquals(
            100.0,
            (float) $this->service->applyAdministrativeDebt(collect([$exempt]))->first()->administrative_debt
        );
        $this->assertEquals(-50.0, (float) $exempt->fund_balance);
    }

    public function test_explicit_repayment_fully_repays_accumulated_debt_from_surplus(): void
    {
        $result = $this->service->repayAdministrativeDebtFromSurplus(
            fundBalance: 150,
            administrativeDebt: 0,
            accumulatedAdministrativeDebt: 100,
        );

        $this->assertSame(
            [
                'fund_balance' => 50.0,
                'administrative_debt' => 0.0,
                'accumulated_administrative_debt' => 0.0,
            ],
            $result
        );
    }

    public function test_explicit_repayment_partially_repays_accumulated_debt_from_surplus(): void
    {
        $result = $this->service->repayAdministrativeDebtFromSurplus(
            fundBalance: 40,
            administrativeDebt: 0,
            accumulatedAdministrativeDebt: 100,
        );

        $this->assertSame(
            [
                'fund_balance' => 0.0,
                'administrative_debt' => 0.0,
                'accumulated_administrative_debt' => 60.0,
            ],
            $result
        );
    }

    public function test_explicit_repayment_repays_today_debt_before_accumulated(): void
    {
        $result = $this->service->repayAdministrativeDebtFromSurplus(
            fundBalance: 30,
            administrativeDebt: 20,
            accumulatedAdministrativeDebt: 50,
        );

        $this->assertSame(
            [
                'fund_balance' => 0.0,
                'administrative_debt' => 0.0,
                'accumulated_administrative_debt' => 20.0,
            ],
            $result
        );
    }

    public function test_save_workflow_rolls_back_on_failure(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->mock(UpdateDailyJournalReportsAction::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('report failed'));
        });

        $data = DailyJournalData::fromArray([
            'journal_date' => now()->toDateString(),
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 100],
            ],
        ]);

        try {
            app(SaveDailyJournalWorkflow::class)->handle($data);
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('report failed', $e->getMessage());
        }

        $this->assertDatabaseMissing('daily_journal_entries', [
            'project_id' => $project->id,
            'journal_date' => now()->toDateString(),
        ]);
    }
}
