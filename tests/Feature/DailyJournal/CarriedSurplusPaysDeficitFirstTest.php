<?php

namespace Tests\Feature\DailyJournal;

use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;

/**
 * Case 1 base = max(carried fund_balance, 0) + today's balance: a carried surplus pays the day's
 * deficit first and only the remainder reaches the administrative fee. A carried deficit is ignored,
 * so today's fee pays today's deficit only.
 *
 * Day two is always income 4250 (fee 510), expense 4368 → daily_total −628.
 */
class CarriedSurplusPaysDeficitFirstTest extends DailyJournalFeatureTestCase
{
    private const DAY_ONE = '2026-08-10';

    private const DAY_TWO = '2026-08-11';

    public function test_small_carried_surplus_pays_first_and_fee_covers_only_the_remainder(): void
    {
        // Carried +300: base = 300 − 628 = −328 → debt 328, fund = 300 + (−628 + 328) = 0.
        $dayTwo = $this->runTwoDays(dayOneIncome: 1000, dayOneExpense: 580, expectedCarried: 300.0);

        $this->assertEqualsWithDelta(-628.0, (float) $dayTwo->daily_total, 0.001);
        $this->assertEqualsWithDelta(328.0, (float) $dayTwo->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->fund_balance, 0.001);
    }

    public function test_large_carried_surplus_covers_the_whole_deficit_with_no_debt(): void
    {
        // Carried +1633: base = 1633 − 628 = 1005 ≥ 0 → no debt, fund 1005.
        $dayTwo = $this->runTwoDays(dayOneIncome: 2000, dayOneExpense: 127, expectedCarried: 1633.0);

        $this->assertEqualsWithDelta(-628.0, (float) $dayTwo->daily_total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $dayTwo->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(1005.0, (float) $dayTwo->fund_balance, 0.001);
    }

    public function test_carried_deficit_is_ignored_and_fee_pays_only_todays_deficit(): void
    {
        // Day one: income 100 (fee 12), expense 1721 → total −1633, Case 1 restores the fee → carried −1621.
        // Day two: base = 0 + (−628) → debt min(628, 510) = 510, fund = −1621 + (−628 + 510) = −1739.
        $dayTwo = $this->runTwoDays(dayOneIncome: 100, dayOneExpense: 1721, expectedCarried: -1621.0);

        $this->assertEqualsWithDelta(510.0, (float) $dayTwo->administrative_debt, 0.001);
        $this->assertEqualsWithDelta(-1739.0, (float) $dayTwo->fund_balance, 0.001);
    }

    private function runTwoDays(float $dayOneIncome, float $dayOneExpense, float $expectedCarried): DailyJournalEntry
    {
        $this->actAsFinanceUser();

        $project = $this->createActiveProject([
            'name' => 'Carry '.uniqid(),
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => self::DAY_ONE,
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => $dayOneIncome, 'daily_expense' => $dayOneExpense],
            ],
        ])->assertOk();

        $dayOne = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', self::DAY_ONE)
            ->firstOrFail();

        $this->assertEqualsWithDelta($expectedCarried, (float) $dayOne->fund_balance, 0.001);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => self::DAY_TWO,
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 4250, 'daily_expense' => 4368],
            ],
        ])->assertOk();

        return DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', self::DAY_TWO)
            ->firstOrFail();
    }
}
