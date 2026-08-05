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

        $cutoff = $settlement->created_at->toDateString();

        $entries = DailyJournalEntry::query()
            ->where('project_id', $settlement->to_project_id)
            ->whereDate('journal_date', '>=', $cutoff)
            ->orderBy('journal_date')
            ->lockForUpdate()
            ->get();

        if ($entries->isEmpty()) {
            return;
        }

        foreach ($entries as $entry) {
            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $amount),
                2,
            );
            $entry->save();
        }

        $settlement->journal_anchor_date = $entries->first()->journal_date;
        $settlement->save();
    }
}
