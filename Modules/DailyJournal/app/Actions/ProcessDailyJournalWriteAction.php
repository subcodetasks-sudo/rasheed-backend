<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\AdministrativePercentageBalanceService;

/**
 * Two-pass Daily Journal write:
 * 1. Persist income/expense with contribution forced to 0; run full calculation pipeline.
 * 2. Validate contribution mutations (super-admin + deficit + admin percentage balance),
 *    apply contribution, permanently debit the org admin pool for increases, re-run
 *    downstream calculations only (preserve fee/op/admin expense), and persist.
 */
class ProcessDailyJournalWriteAction
{
    public function __construct(
        private readonly UpsertDailyJournalEntriesAction $upsertDailyJournalEntriesAction,
        private readonly RecalculateDailyJournalAction $recalculateDailyJournalAction,
        private readonly ValidateDailyJournalContributionsAction $validateDailyJournalContributionsAction,
        private readonly AdministrativePercentageBalanceService $administrativePercentageBalanceService,
    ) {}

    /**
     * @return Collection<int, DailyJournalEntry>
     */
    public function execute(DailyJournalData $data, bool $replaceMissingEditableFields): Collection
    {
        $existing = $this->loadExistingContributionState($data->journalDate->toDateString());

        $pass1Data = $data->withPositiveContributionsZeroed();

        $this->upsertDailyJournalEntriesAction->execute($pass1Data, $replaceMissingEditableFields);
        $pass1 = $this->recalculateDailyJournalAction->execute($data->journalDate);

        $this->validateDailyJournalContributionsAction->execute(
            $data,
            $pass1->remainingDeficits,
            auth()->user(),
            $replaceMissingEditableFields,
            $existing['contributions'],
            $existing['entry_ids'],
        );

        if (! $data->hasPositiveContribution()) {
            return $pass1->entries;
        }

        $this->upsertDailyJournalEntriesAction->execute($data, $replaceMissingEditableFields);

        $entries = $this->recalculateDailyJournalAction->execute(
            $data->journalDate,
            preserveIncomeDerivedDeductions: true,
        )->entries;

        foreach ($entries as $entry) {
            $this->administrativePercentageBalanceService->syncDebitForContribution(
                $entry,
                (float) ($entry->contribution ?? 0),
            );
        }

        return $entries;
    }

    /**
     * @return array{contributions: array<int, float>, entry_ids: array<int, int>}
     */
    private function loadExistingContributionState(string $date): array
    {
        $rows = DailyJournalEntry::query()
            ->whereDate('journal_date', $date)
            ->get(['id', 'project_id', 'contribution']);

        $contributions = [];
        $entryIds = [];

        foreach ($rows as $row) {
            $contributions[(int) $row->project_id] = (float) ($row->contribution ?? 0);
            $entryIds[(int) $row->project_id] = (int) $row->id;
        }

        return [
            'contributions' => $contributions,
            'entry_ids' => $entryIds,
        ];
    }
}
