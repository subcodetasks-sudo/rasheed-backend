<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ReverseContributionFundBalanceAction
{
    /**
     * Withdraw the settlement amount from beneficiary `fund_balance`.
     *
     * Uses `journal_anchor_date` as the month-tip anchor so cancel/reversal
     * stays stable even if the journal is later edited or re-created.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0) {
            return;
        }

        if ($settlement->journal_anchor_date === null) {
            // Pending (never applied to the journal).
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
