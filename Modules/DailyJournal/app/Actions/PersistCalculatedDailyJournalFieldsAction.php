<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;

class PersistCalculatedDailyJournalFieldsAction
{
    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function execute(Collection $entries): Collection
    {
        foreach ($entries as $entry) {
            $entry->save();
        }

        return $entries;
    }
}
