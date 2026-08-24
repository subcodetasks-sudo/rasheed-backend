<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Carbon;
use Modules\DailyJournal\Models\AdminPercentagePoolReliefAllocation;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;

/**
 * Stage 3: same-day Administrative Percentage Pool relief. Verifies deficits still
 * open after Case 1 (§6) are covered from OTHER projects' unused same-day
 * administrative_fee only, with a deterministic project_id-ordered allocation and
 * a full source->beneficiary allocation history.
 */
class AdministrativePoolDeficitReliefTest extends DailyJournalFeatureTestCase
{
    private const DATE = '2026-08-01';

    private const OTHER_DATE = '2026-08-02';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subjectProject(array $attributes = []): Project
    {
        return $this->createActiveProject(array_merge([
            'name' => 'Project '.uniqid(),
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 20,
        ], $attributes));
    }

    private function saveJournal(string $date, array $entries): void
    {
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $date,
            'entries' => $entries,
        ])->assertOk();
    }

    private function entryFor(int $projectId, string $date = self::DATE): DailyJournalEntry
    {
        return DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->whereDate('journal_date', $date)
            ->firstOrFail();
    }

    /** 1. Full coverage: ample same-day pool fully relieves a single beneficiary. */
    public function test_full_coverage_from_ample_same_day_pool(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 12]);

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1000, 'daily_expense' => 1200],
            ['project_id' => $source->id, 'daily_income' => 5000],
        ]);

        // Beneficiary: fee 200; daily_total 1000-1200-200=-400; Case1 uses 200 -> fund -200, debt 200; deficit 200.
        // Source: fee 600; no deficit -> fund_balance 5000-600=4400; remainingFee 600 (case1Used 0).
        // Stage 3 relieves the remaining 200 like Case 1 does: administrative_debt += 200 -> 400; fund_balance += 200 -> 0.
        // It is a real transfer: the source's own fund_balance is drawn down by the same 200 -> 4200.
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $sourceEntry = $this->entryFor($source->id);

        $this->assertEqualsWithDelta(400.0, (float) $beneficiaryEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $beneficiaryEntry->accumulated_administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(4200.0, (float) $sourceEntry->fund_balance, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame($source->id, $allocations->first()->source_project_id);
        $this->assertSame($beneficiary->id, $allocations->first()->beneficiary_project_id);
        $this->assertEqualsWithDelta(200.0, (float) $allocations->first()->amount, 0.001);
    }

    /** 2. Partial coverage: pool smaller than remaining deficit leaves a smaller, real deficit. */
    public function test_partial_coverage_when_pool_smaller_than_deficit(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 20]);

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1000, 'daily_expense' => 2000],
            ['project_id' => $source->id, 'daily_income' => 1500],
        ]);

        // Beneficiary: fee 200; daily_total -1200; Case1 uses 200 -> fund -1000; deficit 1000.
        // Source: fee 300; fund_balance 1500-300=1200; remainingFee 300 (no deficit of its own), fully drawn.
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $sourceEntry = $this->entryFor($source->id);

        $this->assertEqualsWithDelta(-700.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $sourceEntry->fund_balance, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertEqualsWithDelta(300.0, (float) $allocations->sum('amount'), 0.001);
    }

    /** 3. Zero same-day pool (no other project has spare fee) -> no relief, behavior unchanged. */
    public function test_zero_pool_creates_no_relief(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $exemptOther = $this->subjectProject(['administrative_exempt' => true]);

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 100, 'daily_expense' => 500],
            ['project_id' => $exemptOther->id, 'daily_income' => 5000],
        ]);

        // Beneficiary: fee 20; daily_total -420; Case1 uses 20 -> fund -400; deficit 400.
        // Other project is admin_exempt -> fee 0 -> no source capacity.
        $beneficiaryEntry = $this->entryFor($beneficiary->id);

        $this->assertEqualsWithDelta(-400.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertCount(0, AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get());
    }

    /** 4. Multiple source projects: deterministic project_id-ordered split, first source exhausted before next. */
    public function test_multiple_sources_split_deterministically_by_project_id(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_exempt' => true]);
        $sourceB = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 300
        $sourceC = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 500

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_expense' => 700],
            ['project_id' => $sourceB->id, 'daily_income' => 1500],
            ['project_id' => $sourceC->id, 'daily_income' => 2500],
        ]);

        // Beneficiary admin_exempt -> fee 0 -> Case1 debt 0; deficit stays 700.
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(700.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->orderBy('source_project_id')->get();
        $this->assertCount(2, $allocations);
        $this->assertSame($sourceB->id, $allocations[0]->source_project_id);
        $this->assertEqualsWithDelta(300.0, (float) $allocations[0]->amount, 0.001);
        $this->assertSame($sourceC->id, $allocations[1]->source_project_id);
        $this->assertEqualsWithDelta(400.0, (float) $allocations[1]->amount, 0.001);

        // Source B: fund_balance 1500-300=1200, fully drawn -> 900. Source C: fund_balance 2500-500=2000,
        // only 400 of its 500 capacity drawn -> 1600.
        $sourceBEntry = $this->entryFor($sourceB->id);
        $sourceCEntry = $this->entryFor($sourceC->id);
        $this->assertEqualsWithDelta(900.0, (float) $sourceBEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $sourceBEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(1600.0, (float) $sourceCEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $sourceCEntry->administrative_pool_relief_given, 0.001);
        // Zero-sum: total given == total received for the day.
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $totalGiven = (float) $sourceBEntry->administrative_pool_relief_given + (float) $sourceCEntry->administrative_pool_relief_given;
        $this->assertEqualsWithDelta((float) $beneficiaryEntry->administrative_pool_relief, $totalGiven, 0.001);
    }

    /** 5 & 6. Multiple deficit beneficiaries sharing one pool: total relief never exceeds the pool,
     *  and no source's total allocations exceed its own remaining fee. */
    public function test_multiple_deficits_share_pool_without_exceeding_source_capacity(): void
    {
        $this->actAsFinanceUser();

        $beneficiaryA = $this->subjectProject(['administrative_exempt' => true]);
        $beneficiaryB = $this->subjectProject(['administrative_exempt' => true]);
        $sourceC = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 400
        $sourceD = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 300

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiaryA->id, 'daily_expense' => 600],
            ['project_id' => $beneficiaryB->id, 'daily_expense' => 500],
            ['project_id' => $sourceC->id, 'daily_income' => 2000],
            ['project_id' => $sourceD->id, 'daily_income' => 1500],
        ]);

        $entryA = $this->entryFor($beneficiaryA->id);
        $entryB = $this->entryFor($beneficiaryB->id);

        // Pool = 700. A (deficit 600) is processed first (lower project_id): C(400)+D(200) -> fully covered.
        // B (deficit 500) gets whatever's left of D (100) -> partially covered.
        $this->assertEqualsWithDelta(600.0, (float) $entryA->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entryA->fund_balance, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $entryB->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-400.0, (float) $entryB->fund_balance, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertEqualsWithDelta(700.0, (float) $allocations->sum('amount'), 0.001);

        $fromC = $allocations->where('source_project_id', $sourceC->id)->sum('amount');
        $fromD = $allocations->where('source_project_id', $sourceD->id)->sum('amount');
        $this->assertLessThanOrEqual(400.0, (float) $fromC);
        $this->assertLessThanOrEqual(300.0, (float) $fromD);
        $this->assertEqualsWithDelta(400.0, (float) $fromC, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $fromD, 0.001);

        // Source C: fund_balance 2000-400=1600, fully drawn -> 1200. Source D: fund_balance 1500-300=1200,
        // fully drawn (200 to A + 100 to B) -> 900.
        $this->assertEqualsWithDelta(1200.0, (float) $this->entryFor($sourceC->id)->fund_balance, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $this->entryFor($sourceD->id)->fund_balance, 0.001);
    }

    /** 7. Same-day isolation: a prior day's unused fee never relieves a later day's deficit. */
    public function test_prior_day_unused_fee_does_not_relieve_later_day_deficit(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_exempt' => true]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 20]);

        // Day 1: source earns a large fee, no deficit anywhere that day.
        $this->saveJournal(self::DATE, [
            ['project_id' => $source->id, 'daily_income' => 5000],
        ]);

        // Day 2: beneficiary has a deficit; source has no income (fee 0) on day 2.
        $this->saveJournal(self::OTHER_DATE, [
            ['project_id' => $beneficiary->id, 'daily_expense' => 300],
        ]);

        $beneficiaryEntry = $this->entryFor($beneficiary->id, self::OTHER_DATE);

        $this->assertEqualsWithDelta(-300.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertCount(0, AdminPercentagePoolReliefAllocation::query()->forDate(self::OTHER_DATE)->get());
    }

    /** 8. Recalculation idempotency: saving the same date again does not double relief or allocation rows. */
    public function test_recalculating_same_date_is_idempotent(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 12]);

        $entries = [
            ['project_id' => $beneficiary->id, 'daily_income' => 1000, 'daily_expense' => 1200],
            ['project_id' => $source->id, 'daily_income' => 5000],
        ];

        $this->saveJournal(self::DATE, $entries);
        $this->saveJournal(self::DATE, $entries);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertEqualsWithDelta(200.0, (float) $allocations->first()->amount, 0.001);

        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(200.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);

        // Re-saving must not double-draw the source either: still exactly one draw of 200, not 400.
        $sourceEntry = $this->entryFor($source->id);
        $this->assertEqualsWithDelta(4200.0, (float) $sourceEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
    }

    /** 9 & 10. Editing a source's income later rebuilds the allocation history, not appends to it. */
    public function test_editing_source_income_rebuilds_allocation_history(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 12]);

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1000, 'daily_expense' => 1200],
            ['project_id' => $source->id, 'daily_income' => 5000],
        ]);

        $firstAllocation = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->sole();
        $this->assertEqualsWithDelta(200.0, (float) $firstAllocation->amount, 0.001);

        // Shrink the source's income so its remaining fee capacity drops below the deficit.
        $this->saveJournal(self::DATE, [
            ['project_id' => $source->id, 'daily_income' => 1000],
        ]);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(1, $allocations, 'stale allocation rows must be replaced, not accumulated');
        $this->assertEqualsWithDelta(120.0, (float) $allocations->first()->amount, 0.001);

        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(120.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(-80.0, (float) $beneficiaryEntry->fund_balance, 0.001);

        // Source: income now 1000, fee 120 (12%); fund_balance 1000-120=880, fully drawn -> 760.
        $sourceEntry = $this->entryFor($source->id);
        $this->assertEqualsWithDelta(760.0, (float) $sourceEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
    }

    /**
     * 12. Zero-sum transfer: a beneficiary's gain is exactly matched by the source's loss, so Stage 3
     * never inflates or deflates the org-wide total fund_balance — it only redistributes it.
     */
    public function test_stage3_is_a_zero_sum_transfer_between_source_and_beneficiary(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 12]);

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1000, 'daily_expense' => 1200],
            ['project_id' => $source->id, 'daily_income' => 5000],
        ]);

        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $sourceEntry = $this->entryFor($source->id);

        $totalBefore = -200.0 + 4400.0; // fund_balance right after Case 1, before Stage 3 runs
        $totalAfter = (float) $beneficiaryEntry->fund_balance + (float) $sourceEntry->fund_balance;

        $this->assertEqualsWithDelta($totalBefore, $totalAfter, 0.001, 'Stage 3 must redistribute, not create, cash');
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(4200.0, (float) $sourceEntry->fund_balance, 0.001);
    }

    /**
     * 11. Stage 3 relief is recorded as administrative_debt today — exactly like Case 1, just funded from
     * another project's fee — so it flows into today's accumulated_administrative_debt. It must NOT,
     * however, retroactively repay accumulated debt already carried in from a prior day; that stays
     * exclusively manual via POST /daily-journals/repay-debt.
     */
    public function test_stage3_relief_is_debt_today_and_never_repays_prior_accumulated_debt(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 12]);

        // Day 1: beneficiary has its own Case 1 deficit; no Stage 3 source exists yet.
        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 200, 'daily_expense' => 300],
        ]);
        // fee 40; daily_total -140; Case1 debt = min(140, 40) = 40; fund -100; accumulated 40.
        $dayOneEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(40.0, (float) $dayOneEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $dayOneEntry->accumulated_administrative_debt, 0.001);

        // Day 2: a fresh deficit, now with an ample Stage 3 source available.
        $this->saveJournal(self::OTHER_DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1000, 'daily_expense' => 1200],
            ['project_id' => $source->id, 'daily_income' => 5000],
        ]);
        // Day 2: fee 200; daily_total -400; fund -100(prev)-400=-500; Case1 uses 200 -> fund -300, debt 200.
        // Stage 3 relieves the remaining 300 (source has 600 available) -> fund 0; debt 200+300=500.
        $dayTwoEntry = $this->entryFor($beneficiary->id, self::OTHER_DATE);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwoEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $dayTwoEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(540.0, (float) $dayTwoEntry->accumulated_administrative_debt, 0.001);

        // Day 1's own accumulated debt is untouched by day 2's Stage 3 relief.
        $dayOneEntry->refresh();
        $this->assertEqualsWithDelta(40.0, (float) $dayOneEntry->accumulated_administrative_debt, 0.001);
    }
}
