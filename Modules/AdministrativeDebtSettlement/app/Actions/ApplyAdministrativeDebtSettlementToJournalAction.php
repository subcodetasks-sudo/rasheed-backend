<?php

namespace Modules\AdministrativeDebtSettlement\Actions;

use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyAdministrativeDebtSettlementToJournalAction
{
    /**
     * Reduce the project's journal debt chain so later days inherit the settled balance.
     *
     * Mutates the latest entry on/before the settlement month end (accumulated only),
     * then reduces `accumulated_administrative_debt` on later entries by the same amount.
     * Does not change `administrative_debt` or `fund_balance` (preserves Monthly Total / Net Cash;
     * pool credit is separate).
     */
    public function execute(AdministrativeDebtSettlement $settlement): void
    {
        $debtApplied = round(
            (float) $settlement->allocated_current_debt + (float) $settlement->allocated_carried_debt,
            2,
        );

        if ($debtApplied <= 0) {
            return;
        }

        $endOfMonth = sprintf(
            '%04d-%02d-%02d',
            $settlement->year,
            $settlement->month,
            (int) date('t', mktime(0, 0, 0, $settlement->month, 1, $settlement->year)),
        );

        $anchor = DailyJournalEntry::query()
            ->where('project_id', $settlement->project_id)
            ->whereDate('journal_date', '<=', $endOfMonth)
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($anchor === null) {
            return;
        }

        // Only accumulated is reduced. Day `administrative_debt` stays as calculated so Cash Station
        // collected admin % (fee − debt + contribution) and Net Cash are unchanged.
        $anchor->accumulated_administrative_debt = round(
            max(0, (float) $anchor->accumulated_administrative_debt - $debtApplied),
            2,
        );
        $anchor->save();

        $laterEntries = DailyJournalEntry::query()
            ->where('project_id', $settlement->project_id)
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
            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $debtApplied),
                2,
            );
            $entry->save();
        }
    }
}
