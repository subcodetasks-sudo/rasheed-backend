<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyContributionFundBalanceAction
{
    /**
     * Inject the settlement amount into beneficiary `fund_balance` starting
     * from the month tip that exists at apply-time.
     *
     * If the qualifying journal tip doesn't exist yet, the settlement stays
     * pending (`journal_anchor_date` remains null) and will be retried on the
     * next matching `DailyJournalUpdated` event.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0) {
            return;
        }

        if ($settlement->journal_anchor_date !== null) {
            return;
        }

        $projectId = $settlement->to_project_id;
        $year = $settlement->year;
        $month = $settlement->month;

        $endOfMonth = sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            (int) date('t', mktime(0, 0, 0, $month, 1, $year)),
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
