<?php

namespace Tests\Feature\DailyJournal;

use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;

/**
 * A debt settlement stays on the journal entry it was applied to. A day added later inside the
 * settled month inherits the reduction through the carried balances and is not reduced again.
 */
class SettlementAnchorStaysTest extends DailyJournalFeatureTestCase
{
    public function test_late_day_in_a_settled_month_is_not_reduced_a_second_time(): void
    {
        $this->actAsFinanceUser();

        $project = $this->createActiveProject([
            'name' => 'Anchor '.uniqid(),
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $save = fn (string $date, array $entry) => $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $date,
            'entries' => [array_merge(['project_id' => $project->id], $entry)],
        ])->assertOk();

        $row = fn (string $date) => DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', $date)
            ->firstOrFail();

        $save('2026-08-10', ['daily_income' => 100, 'daily_expense' => 200]); // fee 12, debt 12, fund -100
        $save('2026-08-12', ['daily_income' => 1000, 'daily_expense' => 0]);  // fund 780, acc 12

        $this->postJson('/api/v1/administrative-debt-settlements', [
            'year' => 2026,
            'month' => 8,
            'project_id' => $project->id,
        ])->assertCreated();

        $settlement = AdministrativeDebtSettlement::query()->where('project_id', $project->id)->firstOrFail();
        $this->assertSame('2026-08-12', $settlement->journal_anchor_date->toDateString());
        $this->assertEqualsWithDelta(768.0, (float) $row('2026-08-12')->fund_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $row('2026-08-12')->accumulated_administrative_debt, 0.001);

        // A later day inside the same month: 768 carried + 440 = 1208, settlement counted once.
        $save('2026-08-20', ['daily_income' => 500, 'daily_expense' => 0]);

        $this->assertEqualsWithDelta(1208.0, (float) $row('2026-08-20')->fund_balance, 0.001);
        $this->assertSame('2026-08-12', $settlement->fresh()->journal_anchor_date->toDateString());

        // Re-saving the anchor day keeps the settlement on it and the later day unchanged.
        $save('2026-08-12', ['daily_income' => 1000, 'daily_expense' => 0]);

        $this->assertEqualsWithDelta(768.0, (float) $row('2026-08-12')->fund_balance, 0.001);
        $this->assertEqualsWithDelta(1208.0, (float) $row('2026-08-20')->fund_balance, 0.001);
    }
}
