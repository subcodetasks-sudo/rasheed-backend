<?php

namespace Modules\DailyJournal\Actions;

use Modules\DailyJournal\Models\DailyJournalEntry;

class ReadFundBalanceTipAction
{
    /**
     * Read the as-of tip (latest row with journal_date <= $asOfDate) fund_balance per project.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function execute(array $projectIds, string $asOfDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $latestDates = DailyJournalEntry::query()
            ->selectRaw('project_id, MAX(journal_date) as journal_date')
            ->whereIn('project_id', $projectIds)
            ->whereDate('journal_date', '<=', $asOfDate)
            ->groupBy('project_id');

        $latestIds = DailyJournalEntry::query()
            ->selectRaw('daily_journal_entries.project_id, MAX(daily_journal_entries.id) as id')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('daily_journal_entries.project_id', '=', 'latest.project_id')
                    ->on('daily_journal_entries.journal_date', '=', 'latest.journal_date');
            })
            ->groupBy('daily_journal_entries.project_id');

        $rows = DailyJournalEntry::query()
            ->select([
                'daily_journal_entries.project_id',
                'daily_journal_entries.fund_balance',
            ])
            ->joinSub($latestIds, 'tip', function ($join) {
                $join->on('daily_journal_entries.id', '=', 'tip.id');
            })
            ->get()
            ->keyBy('project_id');

        $result = [];

        foreach ($projectIds as $projectId) {
            $row = $rows->get($projectId);
            $result[(int) $projectId] = (float) ($row?->fund_balance ?? 0);
        }

        return $result;
    }
}
