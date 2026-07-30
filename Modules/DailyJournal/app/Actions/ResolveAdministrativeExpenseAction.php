<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Actions\SumAdministrativeExpenseForProjectDateAction;

class ResolveAdministrativeExpenseAction
{
    public function __construct(
        private readonly SumAdministrativeExpenseForProjectDateAction $sumAdministrativeExpenseForProjectDateAction,
    ) {}

    /**
     * Aggregate persisted Inventory Outgoing FIFO total_cost for the beneficiary project + journal date.
     * Never recomputes FIFO.
     *
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function execute(Collection $entries): Collection
    {
        foreach ($entries as $entry) {
            /** @var DailyJournalEntry $entry */
            $entry->administrative_expense = $this->sumAdministrativeExpenseForProjectDateAction->execute(
                (int) $entry->project_id,
                $entry->journal_date,
            );
        }

        return $entries;
    }
}
