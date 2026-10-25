<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\MonthlySummary\Enums\ContributionType;

/**
 * Re-apply previously recorded overlays that mutate `daily_journal_entries`
 * outside the main calculation pipeline.
 *
 * Monthly Summary contributions and Administrative Debt Settlements are anchored
 * to a journal date. Any recalculation of that date (including the forward chain
 * and inventory-triggered recalcs) must restore the overlay so later days inherit
 * the correct fund_balance / accumulated debt.
 */
class ReapplyDailyJournalOverlaysAction
{
    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function execute(Collection $entries, CarbonInterface $journalDate): Collection
    {
        $dateString = $journalDate->toDateString();
        $projectIds = $entries->pluck('project_id')->unique()->values()->all();

        if ($projectIds === []) {
            return $entries;
        }

        $entryByProjectId = $entries->keyBy('project_id');

        $this->reapplyFundDeficitContributions($entryByProjectId, $projectIds, $dateString);
        $this->reapplyAdministrativeDebtContributions($entryByProjectId, $projectIds, $dateString);
        $this->reapplyContributorFundDebits($entryByProjectId, $projectIds, $dateString);
        $this->reapplyAdministrativeDebtSettlements(
            $entryByProjectId,
            $projectIds,
            $dateString,
            (int) $journalDate->year,
            (int) $journalDate->month,
        );

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entryByProjectId
     * @param  list<int>  $projectIds
     */
    private function reapplyFundDeficitContributions(
        Collection $entryByProjectId,
        array $projectIds,
        string $dateString,
    ): void {
        $amountsByProjectId = [];
        $settlements = CashStationSettlement::query()
            ->where('contribution_type', ContributionType::FundDeficit)
            ->whereDate('journal_anchor_date', $dateString)
            ->whereIn('to_project_id', $projectIds)
            ->get(['to_project_id', 'amount']);

        foreach ($settlements as $settlement) {
            $projectId = (int) $settlement->to_project_id;
            $amountsByProjectId[$projectId] = round(
                (float) ($amountsByProjectId[$projectId] ?? 0)
                + (float) $settlement->amount,
                2,
            );
        }

        foreach ($amountsByProjectId as $projectId => $amount) {
            $entry = $entryByProjectId->get($projectId);
            if ($entry === null) {
                continue;
            }

            $entry->fund_balance = round((float) $entry->fund_balance + $amount, 2);
            $entry->save();
        }
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entryByProjectId
     * @param  list<int>  $projectIds
     */
    private function reapplyAdministrativeDebtContributions(
        Collection $entryByProjectId,
        array $projectIds,
        string $dateString,
    ): void {
        $amountsByProjectId = [];
        $settlements = CashStationSettlement::query()
            ->where('contribution_type', ContributionType::AdministrativeDebt)
            ->whereDate('journal_anchor_date', $dateString)
            ->whereIn('to_project_id', $projectIds)
            ->get(['to_project_id', 'amount']);

        foreach ($settlements as $settlement) {
            $projectId = (int) $settlement->to_project_id;
            $amountsByProjectId[$projectId] = round(
                (float) ($amountsByProjectId[$projectId] ?? 0)
                + (float) $settlement->amount,
                2,
            );
        }

        foreach ($amountsByProjectId as $projectId => $amount) {
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
     * Contributor fund debit for both fund_deficit and administrative_debt contributions.
     *
     * @param  Collection<int, DailyJournalEntry>  $entryByProjectId
     * @param  list<int>  $projectIds
     */
    private function reapplyContributorFundDebits(
        Collection $entryByProjectId,
        array $projectIds,
        string $dateString,
    ): void {
        $amountsByProjectId = [];
        $settlements = CashStationSettlement::query()
            ->whereIn('contribution_type', [
                ContributionType::FundDeficit,
                ContributionType::AdministrativeDebt,
            ])
            ->whereDate('from_journal_anchor_date', $dateString)
            ->whereIn('from_project_id', $projectIds)
            ->get(['from_project_id', 'amount']);

        foreach ($settlements as $settlement) {
            $projectId = (int) $settlement->from_project_id;
            $amountsByProjectId[$projectId] = round(
                (float) ($amountsByProjectId[$projectId] ?? 0)
                + (float) $settlement->amount,
                2,
            );
        }

        foreach ($amountsByProjectId as $projectId => $amount) {
            $entry = $entryByProjectId->get($projectId);
            if ($entry === null) {
                continue;
            }

            $entry->fund_balance = round((float) $entry->fund_balance - $amount, 2);
            $entry->save();
        }
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entryByProjectId
     * @param  list<int>  $projectIds
     */
    private function reapplyAdministrativeDebtSettlements(
        Collection $entryByProjectId,
        array $projectIds,
        string $dateString,
        int $journalYear,
        int $journalMonth,
    ): void {
        $adsSettlements = AdministrativeDebtSettlement::query()
            ->whereIn('project_id', $projectIds)
            ->where(function ($query) use ($journalYear, $journalMonth) {
                $query->where('year', '>', $journalYear)
                    ->orWhere(function ($nested) use ($journalYear, $journalMonth) {
                        $nested->where('year', $journalYear)
                            ->where('month', '>=', $journalMonth);
                    });
            })
            ->get(['project_id', 'year', 'month', 'allocated_current_debt', 'allocated_carried_debt', 'recoverable_amount', 'journal_anchor_date']);

        $adsDebtAppliedByProjectId = [];
        $adsFundDebitByProjectId = [];
        foreach ($adsSettlements as $settlement) {
            // The overlay stays on the entry the settlement was applied to. A day added later inside
            // the settled month inherits the reduction through the carried balances and must not be
            // reduced again. Rows without a stored anchor fall back to the month-end entry.
            $anchorDate = $settlement->journal_anchor_date?->toDateString();

            if ($anchorDate === null) {
                $endOfMonth = sprintf(
                    '%04d-%02d-%02d',
                    (int) $settlement->year,
                    (int) $settlement->month,
                    (int) date('t', mktime(0, 0, 0, (int) $settlement->month, 1, (int) $settlement->year)),
                );

                $anchorDate = DailyJournalEntry::query()
                    ->where('project_id', $settlement->project_id)
                    ->whereDate('journal_date', '<=', $endOfMonth)
                    ->orderByDesc('journal_date')
                    ->orderByDesc('id')
                    ->first(['journal_date'])?->journal_date?->toDateString();
            }

            if ($anchorDate === null || $anchorDate !== $dateString) {
                continue;
            }

            $projectId = (int) $settlement->project_id;
            $debtApplied = round(
                (float) $settlement->allocated_current_debt + (float) $settlement->allocated_carried_debt,
                2,
            );

            $fundDebit = round((float) $settlement->recoverable_amount, 2);

            if ($debtApplied <= 0 && $fundDebit <= 0) {
                continue;
            }

            $adsDebtAppliedByProjectId[$projectId] = round(
                (float) ($adsDebtAppliedByProjectId[$projectId] ?? 0) + $debtApplied,
                2,
            );
            $adsFundDebitByProjectId[$projectId] = round(
                (float) ($adsFundDebitByProjectId[$projectId] ?? 0) + $fundDebit,
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
            // Settlement surplus consumed leaves Net Cash (fund_balance) at the anchor entry;
            // later entries inherit it through the carried previous balance.
            $entry->fund_balance = round(
                (float) $entry->fund_balance - (float) ($adsFundDebitByProjectId[$projectId] ?? 0),
                2,
            );
            $entry->save();
        }
    }
}
