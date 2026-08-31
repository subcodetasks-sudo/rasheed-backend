<?php

namespace Tests\Feature\DailyJournal;

use Modules\DailyJournal\Models\AdminPercentagePoolReliefAllocation;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;

/**
 * Case 1 and Stage 3 apply to today's operating deficit only; carried fund_balance
 * is added afterward: fund_balance = carried + (Stage3 + Case1 − today_total).
 */
class CarriedBalanceCaseOneTodayOnlyTest extends DailyJournalFeatureTestCase
{
    private const DAY_ONE = '2026-08-10';

    private const DAY_TWO = '2026-08-11';

    public function test_carried_deficit_plus_today_deficit_matches_stakeholder_formula(): void
    {
        $this->actAsFinanceUser();

        $beneficiary = $this->createActiveProject([
            'name' => 'Beneficiary '.uniqid(),
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $source = $this->createActiveProject([
            'name' => 'Pool Source '.uniqid(),
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        // Build carried tip −1262 on day one.
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => self::DAY_ONE,
            'entries' => [
                ['project_id' => $beneficiary->id, 'daily_income' => 100, 'daily_expense' => 1362],
            ],
        ])->assertOk();

        $dayOne = DailyJournalEntry::query()
            ->where('project_id', $beneficiary->id)
            ->whereDate('journal_date', self::DAY_ONE)
            ->firstOrFail();

        $this->assertEqualsWithDelta(-1262.0, (float) $dayOne->fund_balance, 0.001);

        // Day two: daily_total −638 (income 4250 → fee 510; expense 4378).
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => self::DAY_TWO,
            'entries' => [
                [
                    'project_id' => $beneficiary->id,
                    'daily_income' => 4250,
                    'daily_expense' => 4378,
                ],
                [
                    'project_id' => $source->id,
                    'daily_income' => 5000,
                ],
            ],
        ])->assertOk();

        $dayTwo = DailyJournalEntry::query()
            ->where('project_id', $beneficiary->id)
            ->whereDate('journal_date', self::DAY_TWO)
            ->firstOrFail();

        $this->assertEqualsWithDelta(-638.0, (float) $dayTwo->daily_total, 0.001);
        $this->assertEqualsWithDelta(510.0, (float) $dayTwo->administrative_fee, 0.001);
        $this->assertEqualsWithDelta(510.0, (float) $dayTwo->administrative_debt - (float) $dayTwo->administrative_pool_relief, 0.001);

        // Today after Case 1: −128; Stage 3 relieves full 128 from source → today 0; fund stays −1262.
        $this->assertEqualsWithDelta(128.0, (float) $dayTwo->administrative_pool_relief, 0.001);
        $this->assertEqualsWithDelta(-1262.0, (float) $dayTwo->fund_balance, 0.001);
        $this->assertEqualsWithDelta(638.0, (float) $dayTwo->administrative_debt, 0.001);

        $allocations = AdminPercentagePoolReliefAllocation::query()
            ->whereDate('journal_date', self::DAY_TWO)
            ->get();

        $this->assertCount(1, $allocations);
        $this->assertEqualsWithDelta(128.0, (float) $allocations->first()->amount, 0.001);
    }
}
