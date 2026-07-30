<?php

namespace Modules\DailyJournal\DTOs;

use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;

readonly class DailyJournalRecalculationResult
{
    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @param  array<int, float>  $remainingDeficits  project_id => abs(negative signed fund balance) from Pass 1
     */
    public function __construct(
        public Collection $entries,
        public array $remainingDeficits,
    ) {}
}
