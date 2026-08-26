<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Actions\AssertFinancialMonthUnlockedAction;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyContributionFundBalanceAction
{
    public function __construct(
        private readonly ApplyContributionContributorFundDebitAction $applyContributionContributorFundDebitAction,
        private readonly AssertFinancialMonthUnlockedAction $assertFinancialMonthUnlockedAction,
    ) {}

    /**
     * Credit beneficiary fund_balance and debit contributor fund_balance from each
     * project's month tip. Sides apply independently; missing tips stay pending
     * (`journal_anchor_date` / `from_journal_anchor_date` null) for retry.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        if ($this->assertFinancialMonthUnlockedAction->isLocked(
            (int) $settlement->year,
            (int) $settlement->month,
        )) {
            return;
        }

        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0) {
            return;
        }

        $this->applyBeneficiaryCredit($settlement, $amount);
        $this->applyContributionContributorFundDebitAction->execute($settlement);
    }

    private function applyBeneficiaryCredit(CashStationSettlement $settlement, float $amount): void
    {
        if ($settlement->journal_anchor_date !== null) {
            return;
        }

        $projectId = $settlement->to_project_id;
        $endOfMonth = sprintf(
            '%04d-%02d-%02d',
            $settlement->year,
            $settlement->month,
            (int) date('t', mktime(0, 0, 0, $settlement->month, 1, $settlement->year)),
        );

        $anchor = DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->whereDate('journal_date', '<=', $endOfMonth)
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($anchor === null) {
            return;
        }

        $anchor->fund_balance = round((float) $anchor->fund_balance + $amount, 2);
        $anchor->save();

        $laterEntries = DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->where(function ($query) use ($anchor) {
                $query->whereDate('journal_date', '>', $anchor->journal_date)
                    ->orWhere(function ($sameDay) use ($anchor) {
                        $sameDay->whereDate('journal_date', $anchor->journal_date)
                            ->where('id', '>', $anchor->id);
                    });
            })
            ->orderBy('journal_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($laterEntries as $entry) {
            $entry->fund_balance = round((float) $entry->fund_balance + $amount, 2);
            $entry->save();
        }

        $settlement->journal_anchor_date = $anchor->journal_date;
        $settlement->save();
    }
}
