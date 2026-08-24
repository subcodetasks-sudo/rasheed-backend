<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ReverseContributionFundBalanceAction
{
    public function __construct(
        private readonly ReverseContributionContributorFundDebitAction $reverseContributionContributorFundDebitAction,
    ) {}

    /**
     * Withdraw the settlement amount from beneficiary fund_balance and restore
     * the contributor debit. Each side is independent based on its anchor date.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0) {
            return;
        }

        $this->reverseBeneficiaryCredit($settlement, $amount);
        $this->reverseContributionContributorFundDebitAction->execute($settlement);
    }

    private function reverseBeneficiaryCredit(CashStationSettlement $settlement, float $amount): void
    {
        if ($settlement->journal_anchor_date === null) {
            return;
        }

        $projectId = $settlement->to_project_id;
        $anchorDate = $settlement->journal_anchor_date->toDateString();

        $anchor = DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->whereDate('journal_date', $anchorDate)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($anchor === null) {
            return;
        }

        $anchor->fund_balance = round((float) $anchor->fund_balance - $amount, 2);
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
            $entry->fund_balance = round((float) $entry->fund_balance - $amount, 2);
            $entry->save();
        }
    }
}
