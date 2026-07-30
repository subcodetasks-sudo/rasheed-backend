<?php

namespace Modules\DailyJournal\Workflows;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\DailyJournal\Actions\ProcessDailyJournalWriteAction;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\DailyJournal\Models\DailyJournalEntry;

class UpdateDailyJournalWorkflow
{
    public function __construct(
        private readonly ProcessDailyJournalWriteAction $processDailyJournalWriteAction,
    ) {}

    /**
     * @return Collection<int, DailyJournalEntry>
     */
    public function handle(DailyJournalData $data): Collection
    {
        $entries = DB::transaction(function () use ($data) {
            return $this->processDailyJournalWriteAction->execute($data, replaceMissingEditableFields: false);
        });

        DailyJournalUpdated::dispatch($data->journalDate, $entries);

        return $entries;
    }
}
