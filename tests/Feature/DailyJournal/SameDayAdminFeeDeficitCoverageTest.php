<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Carbon;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Project\Enums\OperationalDeductionType;
use Spatie\Permission\Models\Role;

/**
 * Verifies Case 1 administrative debt: deficit coverage uses only the same day's
 * full administrative fee. No cross-day carry-over of unused fee.
 */
class SameDayAdminFeeDeficitCoverageTest extends DailyJournalFeatureTestCase
{
    private const DAY_ONE = '2026-08-01';

    private const DAY_TWO = '2026-08-02';

    private const DAY_THREE = '2026-08-03';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subjectProject(array $attributes = [])
    {
        return $this->createActiveProject(array_merge([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ], $attributes));
    }

    private function saveJournal(string $date, int $projectId, array $fields = []): DailyJournalEntry
    {
        $payload = array_merge([
            'journal_date' => $date,
            'entries' => [
                array_merge(['project_id' => $projectId], $fields),
            ],
        ]);

        $this->putJson('/api/v1/daily-journals', $payload)->assertOk();

        return DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->whereDate('journal_date', $date)
            ->firstOrFail();
    }

    /** 1. Deficit with sufficient same-day fee → debt = min(deficit, fee). */
    public function test_deficit_with_sufficient_same_day_fee_creates_capped_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $entry = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 200,
            'daily_expense' => 300,
        ]);

        // fee 24; daily_total -124; fund -124 → debt min(124, 24) = 24; fund restored to -100
        $this->assertEqualsWithDelta(24.0, (float) $entry->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(-100.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 2. Deficit larger than fee → debt capped at full same-day fee. */
    public function test_deficit_larger_than_fee_caps_debt_at_fee(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $entry = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 100,
            'daily_expense' => 500,
        ]);

        // fee 12; daily_total -412 → debt = 12; fund restored to -400
        $this->assertEqualsWithDelta(12.0, (float) $entry->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(-400.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 3. Surplus day → Case 1 debt is zero. */
    public function test_surplus_day_creates_no_case1_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $entry = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 1000,
        ]);

        $this->assertEqualsWithDelta(880.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 4. Day 1 unused fee cannot cover Day 2 deficit. */
    public function test_day_one_unused_fee_does_not_cover_day_two_deficit(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $dayOne = $this->saveJournal(self::DAY_ONE, $project->id, ['daily_income' => 1000]);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $project->id, [
            'daily_income' => 200,
            'daily_expense' => 1100,
        ]);

        $this->assertEqualsWithDelta(120.0, (float) $dayOne->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayOne->administrative_debt, 0.001);

        // Day 2 starts from Day 1 fund 880; daily_total 200-1100-24=-924; fund -44; restore 24 → -20
        $this->assertEqualsWithDelta(-20.0, (float) $dayTwo->fund_balance, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $dayTwo->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $dayTwo->administrative_debt, 0.001);
    }

    /** 5. Day 2 surplus does not retroactively reduce Day 1 debt. */
    public function test_day_two_surplus_does_not_retroactively_fix_day_one_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $dayOne = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 200,
            'daily_expense' => 300,
        ]);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $project->id, ['daily_income' => 5000]);

        $dayOne->refresh();
        $this->assertEqualsWithDelta(24.0, (float) $dayOne->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $dayOne->accumulated_administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $dayTwo->accumulated_administrative_debt, 0.001);
    }

    /** 6. Save future day first — Day 1 debt still capped by Day 1 fee only. */
    public function test_saving_future_day_first_still_caps_day_one_debt_by_day_one_fee(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $this->saveJournal(self::DAY_TWO, $project->id, ['daily_income' => 5000]);
        $dayOne = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 200,
            'daily_expense' => 300,
        ]);

        $this->assertEqualsWithDelta(24.0, (float) $dayOne->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $dayOne->administrative_fee, 0.001);
    }

    /** 7. Consecutive deficit and surplus days remain independent. */
    public function test_consecutive_deficit_and_surplus_days_are_independent(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $dayOne = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 100,
            'daily_expense' => 200,
        ]);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $project->id, ['daily_income' => 200]);
        $dayThree = $this->saveJournal(self::DAY_THREE, $project->id, [
            'daily_income' => 50,
            'daily_expense' => 300,
        ]);

        $this->assertEqualsWithDelta(12.0, (float) $dayOne->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->administrative_debt, 0.001);
        // Day 1 fund after cover -100; Day 2 surplus 176 → 76; Day 3 daily_total -256 → fund -180 → debt 6
        $this->assertEqualsWithDelta(6.0, (float) $dayThree->administrative_debt, 0.001);
    }

    /** 8. Editing one day recalculates later dates from persisted fund_balance. */
    public function test_editing_one_day_forward_recalculates_later_dates_from_persisted_fund(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $this->saveJournal(self::DAY_ONE, $project->id, ['daily_income' => 1000]);
        $this->saveJournal(self::DAY_TWO, $project->id, [
            'daily_income' => 200,
            'daily_expense' => 1100,
        ]);

        $this->saveJournal(self::DAY_ONE, $project->id, ['daily_income' => 10000]);

        $dayTwoAfter = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', self::DAY_TWO)
            ->firstOrFail();

        // Day 1 fund 8800 carries; Day 2 daily_total -924 → surplus 7876; no Case 1
        $this->assertEqualsWithDelta(7876.0, (float) $dayTwoAfter->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwoAfter->administrative_debt, 0.001);
    }

    /** 9. Historical dates stay isolated from current-day saves. */
    public function test_historical_dates_remain_isolated(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();
        $historical = '2026-07-15';

        $historicalEntry = $this->saveJournal($historical, $project->id, [
            'daily_income' => 100,
            'daily_expense' => 250,
        ]);

        $this->saveJournal(now()->toDateString(), $project->id, ['daily_income' => 5000]);

        $historicalEntry->refresh();
        $this->assertEqualsWithDelta(12.0, (float) $historicalEntry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(-150.0, (float) $historicalEntry->fund_balance, 0.001);
    }

    /** 10. Recalculating a single day preserves same-day fee cap. */
    public function test_recalc_on_single_day_preserves_same_day_fee_cap(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 200,
            'daily_expense' => 300,
        ]);

        $this->patchJson('/api/v1/daily-journals', [
            'journal_date' => self::DAY_ONE,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 350],
            ],
        ])->assertOk();

        $entry = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', self::DAY_ONE)
            ->firstOrFail();

        $this->assertEqualsWithDelta(24.0, (float) $entry->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 11. Zero fee with deficit → no debt (negative alone never creates debt). */
    public function test_zero_fee_with_deficit_creates_no_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject(['administrative_exempt' => true]);

        $entry = $this->saveJournal(self::DAY_ONE, $project->id, [
            'daily_income' => 100,
            'daily_expense' => 500,
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(-400.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 12. Coexistence: admin expense + deficit — fee covers deficit only; expense via Procedure B. */
    public function test_admin_expense_and_deficit_coexist_without_fee_paying_expense(): void
    {
        $this->actAsFinanceUser();
        Role::findOrCreate('inventory', 'web');
        $this->actAsInventoryUser();

        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'Beneficiary '.uniqid()]);

        Carbon::setTestNow(Carbon::parse(self::DAY_ONE.' 12:00:00'));

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Chair '.uniqid(),
            'category_id' => InventoryCategory::factory()->create(['name' => 'office-'.uniqid()])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => 100,
            'opening_quantity' => 2,
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ])->assertCreated();

        $this->actAsFinanceUser();

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, [
            'daily_income' => 60,
        ]);

        // fee 7.2; daily_total 52.8; surplus covers 52.8 of expense 100
        $this->assertEqualsWithDelta(100.0, (float) $entry->administrative_expense, 0.001);
        $this->assertEqualsWithDelta(47.2, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);
    }
}
