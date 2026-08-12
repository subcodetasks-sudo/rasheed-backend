<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Carbon;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;
use Spatie\Permission\Models\Role;

/**
 * Verifies same-day administrative-expense coverage, per-project carry-forward tip,
 * and later settlement from remaining surplus after covering today's expense.
 */
class AdministrativeExpenseCoverageTest extends DailyJournalFeatureTestCase
{
    private const DAY_ONE = '2026-08-01';

    private const DAY_TWO = '2026-08-02';

    private const ADMIN_FUND_ENDPOINT = '/api/v1/administrative-fund';

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

    private function subjectProject(array $attributes = []): Project
    {
        return $this->createActiveProject(array_merge([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ], $attributes));
    }

    private function saveJournal(string $date, int $projectId, array $fields = []): DailyJournalEntry
    {
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $date,
            'entries' => [array_merge(['project_id' => $projectId], $fields)],
        ])->assertOk();

        return DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->whereDate('journal_date', $date)
            ->firstOrFail();
    }

    private function postAdministrativeOutgoing(Project $owner, Project $beneficiary, float $unitCost = 100, int $qty = 1, string $date = self::DAY_ONE): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));

        Role::findOrCreate('inventory', 'web');
        $this->actAsInventoryUser();

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Admin Item '.uniqid(),
            'category_id' => InventoryCategory::factory()->create(['name' => 'office-'.uniqid()])->id,
            'project_id' => $owner->id,
            'unit' => 'pc',
            'opening_price' => $unitCost,
            'opening_quantity' => max(2, $qty),
        ])->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => $qty,
            'beneficiary_project_id' => $beneficiary->id,
            'expense_type' => InventoryExpenseType::Administrative->value,
        ])->assertCreated();

        $this->actAsFinanceUser();

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));
    }

    private function assertRoundedMoneyEquals(float $expected, string|float|null $actual): void
    {
        $this->assertEqualsWithDelta(round($expected), (float) $actual, 0.001);
    }

    private function assertMoneyEquals(float $expected, string|float|null $actual): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.001);
    }

    private function adminFundDay(string $date): array
    {
        $data = $this->getJson(self::ADMIN_FUND_ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->json('data');

        foreach ($data['days'] as $row) {
            if ($row['date'] === $date) {
                return $row;
            }
        }

        $this->fail("Day {$date} not found in administrative fund response.");
    }

    /** 1. Surplus fully covers administrative expense. */
    public function test_surplus_fully_covers_administrative_expense(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B1 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 100);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 1000]);

        // daily_total 880; surplus 880; expense 100 → fund 780
        $this->assertEqualsWithDelta(100.0, (float) $entry->administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(780.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);

        $day = $this->adminFundDay(self::DAY_ONE);
        $this->assertRoundedMoneyEquals(0.0, $day['project_administration']);
    }

    /** 2. Surplus exactly equals expense. */
    public function test_surplus_exactly_equals_expense(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B2 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 880);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 1000]);

        $this->assertEqualsWithDelta(880.0, (float) $entry->administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->fund_balance, 0.001);
    }

    /** 3. Partial surplus covers part of expense. */
    public function test_partial_surplus_covers_part_of_expense(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B3 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 100);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 60]);

        // daily_total 52.8; covers 52.8; uncovered 47.2; fund 0; tip 47.2
        $this->assertEqualsWithDelta(47.2, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(47.2, (float) $entry->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->fund_balance, 0.001);

        $day = $this->adminFundDay(self::DAY_ONE);
        $this->assertRoundedMoneyEquals(47.2, $day['project_administration']);
        $this->assertRoundedMoneyEquals(47.2, $day['total_income']);
    }

    /** 4. Zero surplus → full expense uncovered. */
    public function test_zero_surplus_leaves_full_expense_uncovered(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B4 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 80);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, [
            'daily_income' => 100,
            'daily_expense' => 100,
        ]);

        // daily_total -12; intermediate -12; surplus 0
        $this->assertEqualsWithDelta(80.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(-12.0, (float) $entry->fund_balance, 0.001);

        $day = $this->adminFundDay(self::DAY_ONE);
        $this->assertRoundedMoneyEquals(80.0, $day['project_administration']);
    }

    /** 5. Zero administrative expense → uncovered zero. */
    public function test_zero_administrative_expense_yields_zero_uncovered(): void
    {
        $this->actAsFinanceUser();
        $project = $this->subjectProject();

        $entry = $this->saveJournal(self::DAY_ONE, $project->id, ['daily_income' => 500]);

        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->uncovered_administrative_expense, 0.001);
    }

    /** 6. Multi-project aggregation sums uncovered amounts. */
    public function test_multi_project_uncovered_expense_aggregates_in_administrative_fund(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();

        $p1 = $this->subjectProject(['name' => 'P1 '.uniqid()]);
        $p2 = $this->subjectProject(['name' => 'P2 '.uniqid()]);
        $p3 = $this->subjectProject(['name' => 'P3 '.uniqid()]);

        foreach ([[$p1, 40], [$p2, 30], [$p3, 20]] as [$project, $cost]) {
            $this->postAdministrativeOutgoing($owner, $project, $cost);
            $this->saveJournal(self::DAY_ONE, $project->id, ['daily_income' => 0]);
        }

        $day = $this->adminFundDay(self::DAY_ONE);
        $this->assertRoundedMoneyEquals(90.0, $day['project_administration']);
    }

    /** 7. Fully covered project excluded from aggregate (contributes 0). */
    public function test_fully_covered_project_contributes_zero_to_aggregate(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();

        $covered = $this->subjectProject(['name' => 'Covered '.uniqid()]);
        $uncovered = $this->subjectProject(['name' => 'Uncovered '.uniqid()]);

        $this->postAdministrativeOutgoing($owner, $covered, 50);
        $this->saveJournal(self::DAY_ONE, $covered->id, ['daily_income' => 1000]);

        $this->postAdministrativeOutgoing($owner, $uncovered, 40);
        $this->saveJournal(self::DAY_ONE, $uncovered->id, ['daily_income' => 0]);

        $day = $this->adminFundDay(self::DAY_ONE);
        $this->assertRoundedMoneyEquals(40.0, $day['project_administration']);
    }

    /** 8. Admin fee present but not used for expense coverage. */
    public function test_admin_fee_is_not_used_for_expense_coverage(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B8 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 80);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 500]);

        // fee 60; expense 80; surplus 440 covers 80 — fee untouched for expense
        $this->assertEqualsWithDelta(60.0, (float) $entry->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 9. Fee larger than uncovered expense still does not pay expense. */
    public function test_fee_larger_than_uncovered_still_does_not_pay_expense(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B9 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 100);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 60]);

        $this->assertEqualsWithDelta(7.2, (float) $entry->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(47.2, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 10. Existing deficit is not increased by uncovered expense. */
    public function test_uncovered_expense_does_not_increase_deficit(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B10 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 80);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, [
            'daily_income' => 100,
            'daily_expense' => 200,
        ]);

        // daily_total -112; no surplus for expense; fund stays -112
        $this->assertEqualsWithDelta(-112.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 11. Same-day fee covers deficit separately while expense follows Procedure B. */
    public function test_same_day_fee_covers_deficit_while_expense_uses_surplus_only(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B11 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 30);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, [
            'daily_income' => 100,
            'daily_expense' => 150,
        ]);

        // daily_total -62; surplus 0; uncovered 30; fund -62; debt min(62,12)=12
        $this->assertEqualsWithDelta(30.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(-62.0, (float) $entry->fund_balance, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $entry->administrative_debt, 0.001);
    }

    /** 12. Recalculating updates uncovered and administrative fund aggregate. */
    public function test_recalc_updates_uncovered_and_administrative_fund(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B12 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 100);

        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 60]);
        $this->assertRoundedMoneyEquals(47.2, $this->adminFundDay(self::DAY_ONE)['project_administration']);

        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 1000]);
        $this->assertRoundedMoneyEquals(0.0, $this->adminFundDay(self::DAY_ONE)['project_administration']);
    }

    /** 13. Later surplus settles prior tip; Day 1 uncovered stays associated with Day 1. */
    public function test_later_surplus_settles_prior_outstanding_without_rewriting_day_one_uncovered(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B13 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 100);

        $dayOne = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 60]);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $beneficiary->id, ['daily_income' => 5000]);

        $dayOne->refresh();
        $this->assertEqualsWithDelta(47.2, (float) $dayOne->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(47.2, (float) $dayOne->outstanding_project_administration, 0.001);

        // Day 2: fee 600; daily_total 4400; cover 0 expense; settle full prior 47.2
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(47.2, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(4352.8, (float) $dayTwo->fund_balance, 0.001);

        $this->assertRoundedMoneyEquals(47.2, $this->adminFundDay(self::DAY_ONE)['project_administration']);
        $this->assertRoundedMoneyEquals(0.0, $this->adminFundDay(self::DAY_TWO)['project_administration']);
        $this->assertRoundedMoneyEquals(0.0, $this->adminFundDay(self::DAY_TWO)['total_income']);
    }

    /** 13b. Confirmed Day 2 math: expense 50, surplus 80, prior 100 → settle 30, tip 70. */
    public function test_day_two_covers_today_then_partially_settles_prior_tip(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject([
            'name' => 'B13b '.uniqid(),
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 100, 1, self::DAY_ONE);
        $dayOne = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 0]);
        $this->assertEqualsWithDelta(100.0, (float) $dayOne->outstanding_project_administration, 0.001);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 50, 1, self::DAY_TWO);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $beneficiary->id, ['daily_income' => 80]);

        $dayOne->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $dayOne->uncovered_administrative_expense, 0.001);

        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(70.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->fund_balance, 0.001);

        $this->assertRoundedMoneyEquals(70.0, $this->adminFundDay(self::DAY_TWO)['project_administration']);
        $this->assertRoundedMoneyEquals(0.0, $this->adminFundDay(self::DAY_TWO)['total_income']);
    }

    /** 13c. Zero current expense + surplus settles prior tip. */
    public function test_zero_current_expense_surplus_settles_prior_tip(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject([
            'name' => 'B13c '.uniqid(),
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 60, 1, self::DAY_ONE);
        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 0]);

        $dayTwo = $this->saveJournal(self::DAY_TWO, $beneficiary->id, ['daily_income' => 40]);

        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->administrative_expense, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->fund_balance, 0.001);
    }

    /** 13d. Surplus exactly equals today's expense → no settlement of prior. */
    public function test_surplus_exactly_equals_today_expense_does_not_settle_prior(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject([
            'name' => 'B13d '.uniqid(),
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 100, 1, self::DAY_ONE);
        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 0]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 50, 1, self::DAY_TWO);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $beneficiary->id, ['daily_income' => 50]);

        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->fund_balance, 0.001);
    }

    /** 13e. Partial today coverage → no prior settlement; new uncovered adds to tip. */
    public function test_partial_today_coverage_adds_uncovered_to_tip_without_settling_prior(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject([
            'name' => 'B13e '.uniqid(),
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 100, 1, self::DAY_ONE);
        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 0]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 80, 1, self::DAY_TWO);
        $dayTwo = $this->saveJournal(self::DAY_TWO, $beneficiary->id, ['daily_income' => 30]);

        $this->assertEqualsWithDelta(50.0, (float) $dayTwo->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->fund_balance, 0.001);
    }

    /** 13f. Editing Day 1 refreshes Day 2 tip/settlement via forward chain. */
    public function test_editing_day_one_forward_recalculates_day_two_tip_and_settlement(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject([
            'name' => 'B13f '.uniqid(),
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 100, 1, self::DAY_ONE);
        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 0]);
        $this->saveJournal(self::DAY_TWO, $beneficiary->id, ['daily_income' => 40]);

        $dayTwo = DailyJournalEntry::query()
            ->where('project_id', $beneficiary->id)
            ->whereDate('journal_date', self::DAY_TWO)
            ->firstOrFail();
        $this->assertEqualsWithDelta(40.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $dayTwo->outstanding_project_administration, 0.001);

        // Cover Day 1 expense fully → tip 0; Day 2 should settle 0 and keep surplus.
        $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 1000]);

        $dayTwo->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        // Day 1 surplus (900) carries into Day 2 (+40 income) once prior tip is cleared.
        $this->assertEqualsWithDelta(940.0, (float) $dayTwo->fund_balance, 0.001);
    }

    /** 13g. Fee is not used for prior-tip settlement; debt rules unchanged. */
    public function test_fee_is_not_used_for_prior_tip_settlement(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B13g '.uniqid()]);

        $this->postAdministrativeOutgoing($owner, $beneficiary, 100, 1, self::DAY_ONE);
        $dayOne = $this->saveJournal(self::DAY_ONE, $beneficiary->id, [
            'daily_income' => 100,
            'daily_expense' => 200,
        ]);
        // deficit day: uncovered 100 tip; fee creates debt separately
        $this->assertEqualsWithDelta(100.0, (float) $dayOne->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $dayOne->administrative_debt, 0.001);

        $dayTwo = $this->saveJournal(self::DAY_TWO, $beneficiary->id, [
            'daily_income' => 100,
            'daily_expense' => 100,
        ]);
        // fee 12; daily_total -12; surplus 0 after intermediate → no settlement; tip stays 100
        $this->assertEqualsWithDelta(12.0, (float) $dayTwo->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->project_administration_settled, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $dayTwo->outstanding_project_administration, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $dayTwo->administrative_debt, 0.001);
    }

    /** 14. Uncovered expense never creates administrative debt. */
    public function test_uncovered_expense_never_creates_administrative_debt(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B14 '.uniqid(), 'administrative_exempt' => true]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 200);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, ['daily_income' => 0]);

        $this->assertEqualsWithDelta(200.0, (float) $entry->uncovered_administrative_expense, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->accumulated_administrative_debt, 0.001);
    }

    /** 15. daily_total excludes administrative expense. */
    public function test_daily_total_excludes_administrative_expense(): void
    {
        $this->actAsFinanceUser();
        $owner = $this->subjectProject();
        $beneficiary = $this->subjectProject(['name' => 'B15 '.uniqid()]);
        $this->postAdministrativeOutgoing($owner, $beneficiary, 100);

        $entry = $this->saveJournal(self::DAY_ONE, $beneficiary->id, [
            'daily_income' => 1000,
            'daily_expense' => 50,
        ]);

        // 1000 - 50 - 120 = 830 (expense 100 applied separately)
        $this->assertEqualsWithDelta(830.0, (float) $entry->daily_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $entry->administrative_expense, 0.001);
    }
}
