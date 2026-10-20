<?php

namespace Modules\AdministrativeDebtSettlement\Actions;

use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyAdministrativeDebtSettlementToJournalAction
{
    /**
     * Reduce the project's journal debt chain and net fund so later days inherit the settled balance.
     *
     * Mutates the latest entry on/before the settlement month end, then the same fields on later
     * entries: `accumulated_administrative_debt` drops by the debt allocated, and `fund_balance`
     * (the Net Cash tip every report reads) drops by the surplus consumed (`recoverable_amount`).
     * Does not change the day's `administrative_debt` (Cash Station collected admin % stays as
     * calculated); the pool credit is separate.
     */
    public function execute(AdministrativeDebtSettlement $settlement): void
    {
        $debtApplied = round(
            (float) $settlement->allocated_current_debt + (float) $settlement->allocated_carried_debt,
            2,
        );
        $fundDebit = round((float) $settlement->recoverable_amount, 2);

        if ($debtApplied <= 0 && $fundDebit <= 0) {
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

        $this->reduce($anchor, $debtApplied, $fundDebit);

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
            $this->reduce($entry, $debtApplied, $fundDebit);
        }
    }

    private function reduce(DailyJournalEntry $entry, float $debtApplied, float $fundDebit): void
    {
        $entry->accumulated_administrative_debt = round(
            max(0, (float) $entry->accumulated_administrative_debt - $debtApplied),
            2,
        );
        $entry->fund_balance = round((float) $entry->fund_balance - $fundDebit, 2);
        $entry->save();
    }
}
