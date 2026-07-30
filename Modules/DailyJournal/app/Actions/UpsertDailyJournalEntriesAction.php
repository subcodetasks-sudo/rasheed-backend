<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\DTOs\DailyJournalEntryInput;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Models\Project;

class UpsertDailyJournalEntriesAction
{
    public function execute(DailyJournalData $data, bool $replaceMissingEditableFields = false): Collection
    {
        $userId = auth()->user()?->uuid;
        $date = $data->journalDate->toDateString();
        $activeProjectIds = Project::query()->active()->pluck('id')->all();

        foreach ($data->entries as $entryInput) {
            if (! in_array($entryInput->projectId, $activeProjectIds, true)) {
                continue;
            }

            $entry = DailyJournalEntry::query()
                ->where('project_id', $entryInput->projectId)
                ->whereDate('journal_date', $date)
                ->first();

            if (! $entry) {
                $entry = new DailyJournalEntry([
                    'project_id' => $entryInput->projectId,
                    'journal_date' => $date,
                    'created_by' => $userId,
                ]);
            }

            $this->applyEditableFields($entry, $entryInput, $replaceMissingEditableFields);
            $entry->updated_by = $userId;
            $entry->save();
        }

        return DailyJournalEntry::query()
            ->whereDate('journal_date', $date)
            ->whereIn('project_id', $activeProjectIds)
            ->get();
    }

    private function applyEditableFields(
        DailyJournalEntry $entry,
        DailyJournalEntryInput $input,
        bool $replaceMissingEditableFields
    ): void {
        if ($replaceMissingEditableFields || $input->hasDailyIncome) {
            $entry->daily_income = $input->hasDailyIncome ? $input->dailyIncome : null;
        }

        if ($replaceMissingEditableFields || $input->hasDailyExpense) {
            $entry->daily_expense = $input->hasDailyExpense ? $input->dailyExpense : null;
        }

        if ($replaceMissingEditableFields || $input->hasContribution) {
            $entry->contribution = $input->hasContribution ? $input->contribution : null;
        }
    }
}
