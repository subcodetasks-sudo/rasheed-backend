<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyContributionAdministrativeDebtAction
{
    /**
     * Reduce beneficiary accumulated administrative debt by the settlement amount.
     *
     * Never touches journal entries dated before the settlement was recorded — history stays
     * intact. Only entries on/after that date (existing at call time) are reduced. If none exist
     * yet, the settlement is left pending (`journal_anchor_date` stays null) and is picked up
     * later by `ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate` once a qualifying
     * entry is created.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0 || $settlement->journal_anchor_date !== null) {
            return;
        }

        // Contribution effects are applied to the beneficiary journal state for the
        // selected month (same month tip logic as BroadcastContributionJournalTipAction),
        // not based on when the settlement row was created in the DB.
        $endOfMonth = sprintf(
            '%04d-%02d-%02d',
            $settlement->year,
            $settlement->month,
            (int) date('t', mktime(0, 0, 0, $settlement->month, 1, $settlement->year)),
        );

        $anchor = DailyJournalEntry::query()
            ->where('project_id', $settlement->to_project_id)
            ->whereDate('journal_date', '<=', $endOfMonth)
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($anchor === null) {
            return;
        }

        $entries = DailyJournalEntry::query()
            ->where('project_id', $settlement->to_project_id)
            ->whereDate('journal_date', '>=', $anchor->journal_date)
            ->orderBy('journal_date')
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $amount),
                2,
            );
            $entry->save();
        }

        $settlement->journal_anchor_date = $anchor->journal_date;
        $settlement->save();
    }
}
