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
 * administrative_fee only, with today's operating deficits (`daily_total < 0`)
 * before carried-only deficits, then project_id order within each group, a
 * full source->beneficiary allocation history, and that a project with zero
 * daily_income that day is never a beneficiary regardless of expense.
 *
 * Beneficiary fixtures below use a token daily_income (e.g. 1) alongside the
 * expense that produces their intended deficit, purely to remain Stage 3-eligible
 * under that last rule — it does not change the deficit amount being tested.
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
        // Source fund_balance stays 4400 (fee already in daily_total; no second drain).
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $sourceEntry = $this->entryFor($source->id);

        $this->assertEqualsWithDelta(400.0, (float) $beneficiaryEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $beneficiaryEntry->accumulated_administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $sourceEntry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(4400.0, (float) $sourceEntry->fund_balance, 0.001);

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
        // Source fund stays 1200 (no second drain).
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $sourceEntry = $this->entryFor($source->id);

        $this->assertEqualsWithDelta(-700.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $sourceEntry->fund_balance, 0.001);

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
            ['project_id' => $beneficiary->id, 'daily_income' => 1, 'daily_expense' => 701],
            ['project_id' => $sourceB->id, 'daily_income' => 1500],
            ['project_id' => $sourceC->id, 'daily_income' => 2500],
        ]);

        // Beneficiary admin_exempt -> fee 0 -> Case1 debt 0; deficit stays 700 (token income 1 keeps
        // it Stage3-eligible without changing the deficit amount).
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(700.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->orderBy('source_project_id')->get();
        $this->assertCount(2, $allocations);
        $this->assertSame($sourceB->id, $allocations[0]->source_project_id);
        $this->assertEqualsWithDelta(300.0, (float) $allocations[0]->amount, 0.001);
        $this->assertSame($sourceC->id, $allocations[1]->source_project_id);
        $this->assertEqualsWithDelta(400.0, (float) $allocations[1]->amount, 0.001);

        // Source B: fund_balance 1500-300=1200 (unchanged by Stage 3). Source C: fund_balance 2500-500=2000
        // (unchanged; 400 of 500 capacity given).
        $sourceBEntry = $this->entryFor($sourceB->id);
        $sourceCEntry = $this->entryFor($sourceC->id);
        $this->assertEqualsWithDelta(1200.0, (float) $sourceBEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $sourceBEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $sourceCEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $sourceCEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $sourceBEntry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $sourceCEntry->administrative_fee_remaining, 0.001);
        // Capacity audit: total given == total received for the day.
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
            ['project_id' => $beneficiaryA->id, 'daily_income' => 1, 'daily_expense' => 601],
            ['project_id' => $beneficiaryB->id, 'daily_income' => 1, 'daily_expense' => 501],
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

        // Source C: fund_balance 2000-400=1600 (unchanged). Source D: fund_balance 1500-300=1200 (unchanged).
        $this->assertEqualsWithDelta(1600.0, (float) $this->entryFor($sourceC->id)->fund_balance, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $this->entryFor($sourceD->id)->fund_balance, 0.001);
    }

    /**
     * Today's operating deficit (`daily_total < 0`) is relieved before a carried-only deficit,
     * even when the carried project has the lower project_id (old sort would have preferred it).
     */
    public function test_today_deficit_is_relieved_before_carried_only_deficit(): void
    {
        $this->actAsFinanceUser();

        // Lower id = carried; higher id = today's deficit (proves priority is not project_id-only).
        $carried = $this->subjectProject(['administrative_exempt' => true]);
        $todayDeficit = $this->subjectProject(['administrative_exempt' => true]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 20]);

        $this->assertLessThan($todayDeficit->id, $carried->id);

        // Day 1: carried project builds a tip deficit; no pool source that day.
        $this->saveJournal(self::DATE, [
            ['project_id' => $carried->id, 'daily_expense' => 400],
        ]);
        $this->assertEqualsWithDelta(-400.0, (float) $this->entryFor($carried->id)->fund_balance, 0.001);

        // Day 2: carried has real activity that nets to zero (daily_total ~ 0, tip still negative) —
        // it must stay a genuine (lower-priority) candidate here, not a zero-income day, since a
        // project with zero daily_income is excluded from Stage 3 entirely regardless of expense (see
        // test_carried_deficit_with_zero_income_is_never_a_stage3_beneficiary_regardless_of_expense
        // below). Today-deficit project spends 300 (token income 1 keeps it Stage3-eligible without
        // changing the deficit amount). Source fee = 200 — enough for only one full cover.
        $this->saveJournal(self::OTHER_DATE, [
            ['project_id' => $carried->id, 'daily_income' => 100, 'daily_expense' => 100],
            ['project_id' => $todayDeficit->id, 'daily_income' => 1, 'daily_expense' => 301],
            ['project_id' => $source->id, 'daily_income' => 1000],
        ]);

        $carriedEntry = $this->entryFor($carried->id, self::OTHER_DATE);
        $todayEntry = $this->entryFor($todayDeficit->id, self::OTHER_DATE);
        $sourceEntry = $this->entryFor($source->id, self::OTHER_DATE);

        $this->assertEqualsWithDelta(0.0, (float) $carriedEntry->daily_total, 0.001);
        $this->assertLessThan(0.0, (float) $todayEntry->daily_total);

        // Pool remainingFee = 200 goes entirely to today's deficit first.
        $this->assertEqualsWithDelta(200.0, (float) $todayEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-100.0, (float) $todayEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $carriedEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-400.0, (float) $carriedEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::OTHER_DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame($todayDeficit->id, $allocations->first()->beneficiary_project_id);
        $this->assertEqualsWithDelta(200.0, (float) $allocations->first()->amount, 0.001);
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

        // Day 2: beneficiary has a deficit (token income keeps it Stage3-eligible, isolating this
        // test's actual point — that source's day-1 fee doesn't carry — from the separate
        // zero-income exclusion rule). source has no income (fee 0) on day 2.
        $this->saveJournal(self::OTHER_DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1, 'daily_expense' => 301],
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

        // Re-saving must not double-draw capacity: still exactly one give of 200; source fund stays 4400.
        $sourceEntry = $this->entryFor($source->id);
        $this->assertEqualsWithDelta(4400.0, (float) $sourceEntry->fund_balance, 0.001);
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

        // Source: income now 1000, fee 120 (12%); fund_balance 1000-120=880 (unchanged by Stage 3 give of 120).
        $sourceEntry = $this->entryFor($source->id);
        $this->assertEqualsWithDelta(880.0, (float) $sourceEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
    }

    /**
     * 12. Source fund_balance is unchanged by Stage 3: fee was already deducted in daily_total,
     * so redirecting unused fee capacity must not hit the giver's cash a second time.
     */
    public function test_stage3_does_not_drain_source_fund_balance(): void
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

        // After Case 1 source fund was 4400; Stage 3 must leave it at 4400 while beneficiary moves -200 -> 0.
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(4400.0, (float) $sourceEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
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

    /**
     * A project with zero daily_income that day is never a Stage 3 beneficiary — regardless of how
     * much daily_expense it has, how negative its carried tip is, or how ample and otherwise-unused
     * the pool is. administrative_fee is a percentage of income, so a project earning nothing that
     * day has no fee-generating activity to justify drawing on other projects' fee capacity; it
     * simply carries its deficit forward untouched.
     */
    public function test_carried_deficit_with_zero_income_is_never_a_stage3_beneficiary_regardless_of_expense(): void
    {
        $this->actAsFinanceUser();

        $carriedBeneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 20]);

        // Day 1: builds a carried tip deficit (fee 0 since income is 0; Case 1 is a no-op).
        $this->saveJournal(self::DATE, [
            ['project_id' => $carriedBeneficiary->id, 'daily_income' => 0, 'daily_expense' => 400],
        ]);
        $this->assertEqualsWithDelta(-400.0, (float) $this->entryFor($carriedBeneficiary->id)->fund_balance, 0.001);

        // Day 2: carriedBeneficiary has zero income but a real (non-zero) expense of 50 — proving the
        // exclusion is about income specifically, not "zero activity" — carrying -400-50=-450. An
        // ample, otherwise untouched source is present, but must never be drawn from.
        $this->saveJournal(self::OTHER_DATE, [
            ['project_id' => $carriedBeneficiary->id, 'daily_income' => 0, 'daily_expense' => 50],
            ['project_id' => $source->id, 'daily_income' => 2500],
        ]);

        $beneficiaryEntry = $this->entryFor($carriedBeneficiary->id, self::OTHER_DATE);
        $sourceEntry = $this->entryFor($source->id, self::OTHER_DATE);

        $this->assertEqualsWithDelta(-450.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->administrative_debt, 0.001);

        // Source's fee (500) is entirely unused — proving there was no eligible beneficiary at all,
        // not merely insufficient capacity.
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $sourceEntry->administrative_fee_remaining, 0.001);

        $this->assertCount(0, AdminPercentagePoolReliefAllocation::query()->forDate(self::OTHER_DATE)->get());
    }

    /** A source that itself needed partial Case 1 relief still exposes its unused fee remainder as Stage 3 capacity. */
    public function test_source_with_own_partial_case1_usage_still_has_leftover_capacity_for_others(): void
    {
        $this->actAsFinanceUser();

        $sourceWithOwnDeficit = $this->subjectProject(['administrative_fee_percentage' => 20]);
        $beneficiary = $this->subjectProject(['administrative_fee_percentage' => 20]);

        $this->saveJournal(self::DATE, [
            ['project_id' => $sourceWithOwnDeficit->id, 'daily_income' => 2500, 'daily_expense' => 2400],
            ['project_id' => $beneficiary->id, 'daily_income' => 500, 'daily_expense' => 800],
        ]);

        // sourceWithOwnDeficit: daily_total 100; fee 500; pre-Case1 fund_balance -400.
        // Case1: deficitDebt=min(400,500)=400 -> fund_balance 0, administrative_debt 400.
        // caseOneUsed=400 -> remainingFee=100.
        $sourceEntry = $this->entryFor($sourceWithOwnDeficit->id);
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $sourceEntry->administrative_debt, 0.001);

        // beneficiary: daily_total -300; fee 100; pre-Case1 fund_balance -400.
        // Case1: deficitDebt=min(400,100)=100 -> fund_balance -300, administrative_debt 100; need 300.
        // Stage 3: only 100 available from sourceWithOwnDeficit -> used=100.
        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(-200.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $beneficiaryEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);

        $sourceEntry->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $sourceEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $sourceEntry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $sourceEntry->administrative_fee_remaining, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertEqualsWithDelta(100.0, (float) $allocations->first()->amount, 0.001);
    }

    /** Boundary: source capacity exactly equal to a beneficiary's need must not leak into an untouched second source. */
    public function test_boundary_exact_source_capacity_leaves_second_source_untouched(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_exempt' => true]);
        $source1 = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 500, exact match
        $source2 = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 300, must stay untouched

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1, 'daily_expense' => 501],
            ['project_id' => $source1->id, 'daily_income' => 2500],
            ['project_id' => $source2->id, 'daily_income' => 1500],
        ]);

        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);

        $source1Entry = $this->entryFor($source1->id);
        $this->assertEqualsWithDelta(500.0, (float) $source1Entry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $source1Entry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $source1Entry->fund_balance, 0.001);

        $source2Entry = $this->entryFor($source2->id);
        $this->assertEqualsWithDelta(0.0, (float) $source2Entry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $source2Entry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $source2Entry->fund_balance, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame($source1->id, $allocations->first()->source_project_id);
        $this->assertEqualsWithDelta(500.0, (float) $allocations->first()->amount, 0.001);
    }

    /** Rounding safety: a 3-way split with asymmetric cent amounts must sum exactly, with no drift. */
    public function test_rounding_safety_across_three_way_split_sums_exactly_to_the_cent(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->subjectProject(['administrative_exempt' => true]);
        $s1 = $this->subjectProject(['administrative_fee_percentage' => 10]); // fee 33.33
        $s2 = $this->subjectProject(['administrative_fee_percentage' => 10]); // fee 33.33
        $s3 = $this->subjectProject(['administrative_fee_percentage' => 10]); // fee 33.34

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiary->id, 'daily_income' => 1, 'daily_expense' => 101],
            ['project_id' => $s1->id, 'daily_income' => 333.30],
            ['project_id' => $s2->id, 'daily_income' => 333.30],
            ['project_id' => $s3->id, 'daily_income' => 333.40],
        ]);

        $beneficiaryEntry = $this->entryFor($beneficiary->id);
        $this->assertEqualsWithDelta(0.0, (float) $beneficiaryEntry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $beneficiaryEntry->administrative_pool_relief, 0.001);

        $s1Entry = $this->entryFor($s1->id);
        $s2Entry = $this->entryFor($s2->id);
        $s3Entry = $this->entryFor($s3->id);
        $this->assertEqualsWithDelta(33.33, (float) $s1Entry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(33.33, (float) $s2Entry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(33.34, (float) $s3Entry->administrative_pool_relief_given, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $s1Entry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $s2Entry->administrative_fee_remaining, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $s3Entry->administrative_fee_remaining, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->orderBy('source_project_id')->get();
        $this->assertCount(3, $allocations);
        $this->assertEqualsWithDelta(33.33, (float) $allocations[0]->amount, 0.001);
        $this->assertEqualsWithDelta(33.33, (float) $allocations[1]->amount, 0.001);
        $this->assertEqualsWithDelta(33.34, (float) $allocations[2]->amount, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $allocations->sum('amount'), 0.001);
    }

    /** A third, lower-priority beneficiary can be fully starved (zero relief, no allocation row) once the pool is exhausted. */
    public function test_three_beneficiaries_share_pool_and_the_last_is_fully_starved(): void
    {
        $this->actAsFinanceUser();

        $beneficiaryA = $this->subjectProject(['administrative_exempt' => true]);
        $beneficiaryB = $this->subjectProject(['administrative_exempt' => true]);
        $beneficiaryC = $this->subjectProject(['administrative_exempt' => true]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 20]); // fee 500 < total need 900

        $this->saveJournal(self::DATE, [
            ['project_id' => $beneficiaryA->id, 'daily_income' => 1, 'daily_expense' => 301],
            ['project_id' => $beneficiaryB->id, 'daily_income' => 1, 'daily_expense' => 301],
            ['project_id' => $beneficiaryC->id, 'daily_income' => 1, 'daily_expense' => 301],
            ['project_id' => $source->id, 'daily_income' => 2500],
        ]);

        // A (min(500,300)=300, pool left 200), B (min(200,300)=200, pool left 0), C gets nothing.
        $entryA = $this->entryFor($beneficiaryA->id);
        $entryB = $this->entryFor($beneficiaryB->id);
        $entryC = $this->entryFor($beneficiaryC->id);

        $this->assertEqualsWithDelta(300.0, (float) $entryA->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entryA->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $entryB->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-100.0, (float) $entryB->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entryC->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-300.0, (float) $entryC->fund_balance, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::DATE)->get();
        $this->assertCount(2, $allocations);
        $this->assertEqualsWithDelta(500.0, (float) $allocations->sum('amount'), 0.001);
        $this->assertCount(0, $allocations->where('beneficiary_project_id', $beneficiaryC->id));
    }

    /**
     * Editing an earlier date's carried balance must be reflected in a later, already-saved date's
     * Stage 3 relief via the forward-chain recalculation that runs on every save.
     */
    public function test_forward_chain_recalculation_updates_a_later_days_stage3_relief(): void
    {
        $this->actAsFinanceUser();

        $chainProject = $this->subjectProject(['administrative_exempt' => true]);
        $source = $this->subjectProject(['administrative_fee_percentage' => 20]);

        // Day 1: chainProject builds a carried tip deficit; source is not saved this day (its fee
        // would be 0 anyway) so nothing can relieve chainProject on Day 1 itself.
        $this->saveJournal(self::DATE, [
            ['project_id' => $chainProject->id, 'daily_income' => 0, 'daily_expense' => 200],
        ]);
        $this->assertEqualsWithDelta(-200.0, (float) $this->entryFor($chainProject->id)->fund_balance, 0.001);

        // Day 2: chainProject has real (non-zero-activity) activity netting to 0, tip carried -200 -> need 200.
        // source fee 200 on day 2 fully covers it.
        $this->saveJournal(self::OTHER_DATE, [
            ['project_id' => $chainProject->id, 'daily_income' => 50, 'daily_expense' => 50],
            ['project_id' => $source->id, 'daily_income' => 1000],
        ]);

        $day2Entry = $this->entryFor($chainProject->id, self::OTHER_DATE);
        $this->assertEqualsWithDelta(0.0, (float) $day2Entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $day2Entry->administrative_pool_relief, 0.001);

        // Re-save Day 1 only, raising chainProject's expense from 200 to 500 (source's day-1 entry
        // is not resent; partial-payload saves leave other same-date entries untouched).
        $this->saveJournal(self::DATE, [
            ['project_id' => $chainProject->id, 'daily_income' => 0, 'daily_expense' => 500],
        ]);

        $day1Entry = $this->entryFor($chainProject->id);
        $this->assertEqualsWithDelta(-500.0, (float) $day1Entry->fund_balance, 0.001);

        // Forward-chain recalculation must have updated Day 2: need is now 500, source capacity is
        // still only 200 -> only partial coverage, worse fund_balance than before.
        $day2Entry = $this->entryFor($chainProject->id, self::OTHER_DATE);
        $this->assertEqualsWithDelta(200.0, (float) $day2Entry->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-300.0, (float) $day2Entry->fund_balance, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()->forDate(self::OTHER_DATE)->get();
        $this->assertCount(1, $allocations);
        $this->assertEqualsWithDelta(200.0, (float) $allocations->first()->amount, 0.001);
    }
}
