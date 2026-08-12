<?php

namespace Modules\DailyJournal\Actions;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\DailyJournal\Models\DailyJournalEntry;

class RecalculateDailyJournalForwardChainAction
{
    public function __construct(
        private readonly RecalculateDailyJournalAction $recalculateDailyJournalAction,
    ) {}

    /**
     * Recalculate every journal date strictly after `$fromDate` (ascending).
     * Call after `$fromDate` itself has already been recalculated.
     */
    public function execute(CarbonInterface $fromDate): void
    {
        $dates = DailyJournalEntry::query()
            ->whereDate('journal_date', '>', $fromDate->toDateString())
            ->distinct()
            ->orderBy('journal_date')
            ->pluck('journal_date');

        foreach ($dates as $date) {
            $this->recalculateDailyJournalAction->execute(
                Carbon::parse($date)->startOfDay()
            );
        }
    }
}
