<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\AdministrativePercentageBalanceService;
use Modules\MonthlySummary\Enums\ContributionType;

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
            Auth::user(),
            $replaceMissingEditableFields,
            $existing['contributions'],
            $existing['entry_ids'],
        );

        if (! $data->hasPositiveContribution()) {
            $this->reapplyOverlaysAfterRecalculation($pass1->entries, $data->journalDate);

            return $pass1->entries;
        }

        $this->upsertDailyJournalEntriesAction->execute($data, $replaceMissingEditableFields);

        $entries = $this->recalculateDailyJournalAction->execute(
            $data->journalDate,
            preserveIncomeDerivedDeductions: true,
        )->entries;

        $this->reapplyOverlaysAfterRecalculation($entries, $data->journalDate);

        foreach ($entries as $entry) {
            $this->administrativePercentageBalanceService->syncDebitForContribution(
                $entry,
                (float) ($entry->contribution ?? 0),
            );
        }

        return $entries;
    }

    /**
     * Re-apply previously recorded overlays that mutate `daily_journal_entries`
     * outside the main calculation pipeline.
     *
     * The overlays (MS contributions + Administrative Debt Settlements) are anchored
     * to a specific journal date; when that date is re-calculated, we must restore
     * the overlay effect for that date so downstream derived modules stay consistent.
     */
    private function reapplyOverlaysAfterRecalculation(Collection $entries, $journalDate): void
    {
        $dateString = $journalDate->toDateString();
        $projectIds = $entries->pluck('project_id')->unique()->values()->all();

        if ($projectIds === []) {
            return;
        }

        $entryByProjectId = $entries->keyBy('project_id');

        // 1) Fund-deficit contributions: add to fund_balance on the anchor journal date.
        $fundDeficitAmountsByProjectId = [];
        $fundDeficitSettlements = CashStationSettlement::query()
            ->where('contribution_type', ContributionType::FundDeficit)
            ->whereDate('journal_anchor_date', $dateString)
            ->whereIn('to_project_id', $projectIds)
            ->get(['to_project_id', 'amount']);

        foreach ($fundDeficitSettlements as $settlement) {
            $projectId = (int) $settlement->to_project_id;
            $fundDeficitAmountsByProjectId[$projectId] = round(
                (float) ($fundDeficitAmountsByProjectId[$projectId] ?? 0)
                + (float) $settlement->amount,
                2,
            );
        }

        foreach ($fundDeficitAmountsByProjectId as $projectId => $amount) {
            $entry = $entryByProjectId->get($projectId);
            if ($entry === null) {
                continue;
            }

            $entry->fund_balance = round((float) $entry->fund_balance + $amount, 2);
            $entry->save();
        }

        // 2) Administrative-debt contributions: subtract accumulated debt on the anchor journal date.
        $adminDebtAmountsByProjectId = [];
        $adminDebtSettlements = CashStationSettlement::query()
            ->where('contribution_type', ContributionType::AdministrativeDebt)
            ->whereDate('journal_anchor_date', $dateString)
            ->whereIn('to_project_id', $projectIds)
            ->get(['to_project_id', 'amount']);

        foreach ($adminDebtSettlements as $settlement) {
            $projectId = (int) $settlement->to_project_id;
            $adminDebtAmountsByProjectId[$projectId] = round(
                (float) ($adminDebtAmountsByProjectId[$projectId] ?? 0)
                + (float) $settlement->amount,
                2,
            );
        }

        foreach ($adminDebtAmountsByProjectId as $projectId => $amount) {
            $entry = $entryByProjectId->get($projectId);
            if ($entry === null) {
                continue;
            }

            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $amount),
                2,
            );
            $entry->save();
        }

        // 3) Administrative debt settlements: subtract accumulated debt if this journal date
        // is the month-tip anchor that the settlement would target.
        $journalYear = (int) $journalDate->year;
        $journalMonth = (int) $journalDate->month;

        $adsSettlements = AdministrativeDebtSettlement::query()
            ->whereIn('project_id', $projectIds)
            ->where(function ($query) use ($journalYear, $journalMonth) {
                $query->where('year', '>', $journalYear)
                    ->orWhere(function ($nested) use ($journalYear, $journalMonth) {
                        $nested->where('year', $journalYear)
                            ->where('month', '>=', $journalMonth);
                    });
            })
            ->get(['project_id', 'year', 'month', 'allocated_current_debt', 'allocated_carried_debt']);

        $adsDebtAppliedByProjectId = [];
        foreach ($adsSettlements as $settlement) {
            $endOfMonth = sprintf(
                '%04d-%02d-%02d',
                (int) $settlement->year,
                (int) $settlement->month,
                (int) date('t', mktime(0, 0, 0, (int) $settlement->month, 1, (int) $settlement->year)),
            );

            $anchor = DailyJournalEntry::query()
                ->where('project_id', $settlement->project_id)
                ->whereDate('journal_date', '<=', $endOfMonth)
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->first(['journal_date']);

            if ($anchor === null) {
                continue;
            }

            if ($anchor->journal_date?->toDateString() !== $dateString) {
                continue;
            }

            $projectId = (int) $settlement->project_id;
            $debtApplied = round(
                (float) $settlement->allocated_current_debt + (float) $settlement->allocated_carried_debt,
                2,
            );

            if ($debtApplied <= 0) {
                continue;
            }

            $adsDebtAppliedByProjectId[$projectId] = round(
                (float) ($adsDebtAppliedByProjectId[$projectId] ?? 0) + $debtApplied,
                2,
            );
        }

        foreach ($adsDebtAppliedByProjectId as $projectId => $amount) {
            $entry = $entryByProjectId->get($projectId);
            if ($entry === null) {
                continue;
            }

            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $amount),
                2,
            );
            $entry->save();
        }
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
