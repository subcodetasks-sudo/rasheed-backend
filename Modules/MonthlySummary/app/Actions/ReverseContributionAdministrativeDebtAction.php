<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ReverseContributionAdministrativeDebtAction
{
    /**
     * Restore beneficiary accumulated administrative debt previously reduced by this settlement.
     *
     * No-op if the settlement was never applied to the journal (`journal_anchor_date` is still
     * null) — nothing to undo. Otherwise restores every entry from the recorded anchor date
     * forward, mirroring exactly what was reduced.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0 || $settlement->journal_anchor_date === null) {
            return;
        }

        $entries = DailyJournalEntry::query()
            ->where('project_id', $settlement->to_project_id)
            ->whereDate('journal_date', '>=', $settlement->journal_anchor_date->toDateString())
            ->orderBy('journal_date')
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $entry->accumulated_administrative_debt = round(
                (float) $entry->accumulated_administrative_debt + $amount,
                2,
            );
            $entry->save();
        }
    }
}
