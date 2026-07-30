<?php

namespace Modules\DailyJournal\Workflows;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\DailyJournal\Actions\BuildDailyJournalViewAction;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ShowDailyJournalWorkflow
{
    public function __construct(
        private readonly BuildDailyJournalViewAction $buildDailyJournalViewAction,
    ) {}

    /**
     * @return LengthAwarePaginator<int, DailyJournalEntry>
     */
    public function handle(?CarbonInterface $date = null, int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $date ??= now()->startOfDay();

        return $this->buildDailyJournalViewAction->execute($date, $perPage, $page);
    }
}
