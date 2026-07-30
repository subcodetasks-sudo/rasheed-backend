<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\Models\DailyJournalEntry;

/**
 * Two-pass Daily Journal write:
 * 1. Persist income/expense with contribution forced to 0; run full calculation pipeline.
 * 2. If any contribution > 0, validate against Pass 1 remaining deficits, apply contribution,
 *    re-run downstream calculations only (preserve fee/op/admin expense), and persist.
 */
class ProcessDailyJournalWriteAction
{
    public function __construct(
        private readonly UpsertDailyJournalEntriesAction $upsertDailyJournalEntriesAction,
        private readonly RecalculateDailyJournalAction $recalculateDailyJournalAction,
        private readonly ValidateDailyJournalContributionsAction $validateDailyJournalContributionsAction,
    ) {}

    /**
     * @return Collection<int, DailyJournalEntry>
     */
    public function execute(DailyJournalData $data, bool $replaceMissingEditableFields): Collection
    {
        $pass1Data = $data->withPositiveContributionsZeroed();

        $this->upsertDailyJournalEntriesAction->execute($pass1Data, $replaceMissingEditableFields);
        $pass1 = $this->recalculateDailyJournalAction->execute($data->journalDate);

        if (! $data->hasPositiveContribution()) {
            return $pass1->entries;
        }

        $this->validateDailyJournalContributionsAction->execute(
            $data,
            $pass1->remainingDeficits,
            auth()->user(),
        );

        $this->upsertDailyJournalEntriesAction->execute($data, $replaceMissingEditableFields);

        return $this->recalculateDailyJournalAction->execute(
            $data->journalDate,
            preserveIncomeDerivedDeductions: true,
        )->entries;
    }
}
