<?php

namespace Modules\MonthlySummary\Actions;

use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyContributionAdministrativeDebtAction
{
    /**
     * Reduce beneficiary accumulated administrative debt by $amount (tip + later entries).
     */
    public function execute(int $projectId, int $year, int $month, float $amount): void
    {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return;
        }

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

        $anchor->accumulated_administrative_debt = round(
            max(0, (float) $anchor->accumulated_administrative_debt - $amount),
            2,
        );
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
            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $amount),
                2,
            );
            $entry->save();
        }
    }
}
