<?php

namespace Modules\MonthlySummary\Actions;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\DailyJournal\Models\DailyJournalEntry;

class BroadcastContributionJournalTipAction
{
    /**
     * Push DailyJournalUpdated for the beneficiary tip day so open DJ sessions refresh.
     */
    public function execute(int $projectId, int $year, int $month): void
    {
        $endOfMonth = sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            (int) date('t', mktime(0, 0, 0, $month, 1, $year)),
        );

        $tip = DailyJournalEntry::query()
            ->where('project_id', $projectId)
            ->whereDate('journal_date', '<=', $endOfMonth)
            ->with(['project.category'])
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->first();

        if ($tip === null) {
            return;
        }

        /** @var Collection<int, DailyJournalEntry> $entries */
        $entries = collect([$tip]);

        DailyJournalUpdated::dispatch(
            Carbon::parse($tip->journal_date)->startOfDay(),
            $entries,
        );
    }
}
