<?php

namespace Modules\DailyJournal\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;

class DailyJournalCalculationService
{
    public function __construct(
        private readonly AdministrativeDeductionService $administrativeDeductionService,
        private readonly OperationalDeductionService $operationalDeductionService,
    ) {}

    /**
     * Daily Total = income + contribution - expense - admin fee - operational deduction
     *
     * Administrative expense is applied separately from fund surplus after this step.
     */
    public function calculateDailyTotal(
        float $income,
        float $contribution,
        float $expense,
        float $administrativeFee,
        float $operationalDeduction,
    ): float {
        return round(
            $income
            + $contribution
            - $expense
            - $administrativeFee
            - $operationalDeduction,
            2
        );
    }

    /**
     * Fund Balance = previous day fund balance + daily total
     */
    public function calculateFundBalance(float $previousFundBalance, float $dailyTotal): float
    {
        return round($previousFundBalance + $dailyTotal, 2);
    }

    /**
     * Cover administrative expense from same-day fund surplus only.
     *
     * @return array{covered: float, uncovered: float, fund_balance: float}
     */
    public function calculateAdministrativeExpenseCoverage(
        float $intermediateFundBalance,
        float $administrativeExpense,
    ): array {
        $intermediate = round($intermediateFundBalance, 2);
        $expense = round(max(0, $administrativeExpense), 2);
        $surplus = max(0, $intermediate);
        $covered = round(min($surplus, $expense), 2);
        $uncovered = round($expense - $covered, 2);

        return [
            'covered' => $covered,
            'uncovered' => $uncovered,
            'fund_balance' => round($intermediate - $covered, 2),
        ];
    }

    /**
     * Administrative Fund consumption debt (Case 1 only).
     *
     * Case 1 debt when fund_balance < 0: min(|fund_balance|, administrative_fee)
     *
     * Uses same-day administrative_fee only. Administrative expense is handled
     * separately and never creates administrative debt.
     */
    public function calculateAdministrativeDebt(
        float $fundBalance,
        float $administrativeFee,
    ): float {
        $fee = round(max(0, $administrativeFee), 2);
        $balance = round($fundBalance, 2);

        $deficitDebt = $balance < 0
            ? min(abs($balance), $fee)
            : 0.0;

        return round($deficitDebt, 2);
    }

    /**
     * Accumulated debt = previous accumulated debt + today's administrative debt
     */
    public function calculateAccumulatedAdministrativeDebt(
        float $previousAccumulatedDebt,
        float $dayAdministrativeDebt,
    ): float {
        return round($previousAccumulatedDebt + $dayAdministrativeDebt, 2);
    }

    /**
     * Explicit user-initiated debt repayment using available fund surplus.
     *
     * @return array{fund_balance: float, administrative_debt: float, accumulated_administrative_debt: float}
     */
    public function repayAdministrativeDebtFromSurplus(
        float $fundBalance,
        float $administrativeDebt,
        float $accumulatedAdministrativeDebt,
    ): array {
        $fundBalance = round($fundBalance, 2);
        $administrativeDebt = round(max(0, $administrativeDebt), 2);
        $accumulatedAdministrativeDebt = round(max(0, $accumulatedAdministrativeDebt), 2);

        if ($fundBalance < 0) {
            return [
                'fund_balance' => $fundBalance,
                'administrative_debt' => $administrativeDebt,
                'accumulated_administrative_debt' => $accumulatedAdministrativeDebt,
            ];
        }

        $surplus = $fundBalance;

        $repayToday = min($surplus, $administrativeDebt);
        $administrativeDebt = round($administrativeDebt - $repayToday, 2);
        $surplus = round($surplus - $repayToday, 2);
        $accumulatedAdministrativeDebt = round(max(0, $accumulatedAdministrativeDebt - $repayToday), 2);

        $repayAccumulated = min($surplus, $accumulatedAdministrativeDebt);
        $accumulatedAdministrativeDebt = round($accumulatedAdministrativeDebt - $repayAccumulated, 2);
        $surplus = round($surplus - $repayAccumulated, 2);

        return [
            'fund_balance' => $surplus,
            'administrative_debt' => $administrativeDebt,
            'accumulated_administrative_debt' => $accumulatedAdministrativeDebt,
        ];
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyAdministrativeFees(Collection $entries): Collection
    {
        $journalDate = $entries->first()?->journal_date ?? now();

        foreach ($entries as $entry) {
            $entry->administrative_fee = $this->administrativeDeductionService->calculate(
                $entry->project,
                $entry->incomeAmount(),
                $journalDate,
            );
        }

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyOperationalDeductions(Collection $entries): Collection
    {
        $projects = $entries->map(fn (DailyJournalEntry $entry) => $entry->project)->values();
        $incomes = $entries->mapWithKeys(
            fn (DailyJournalEntry $entry) => [$entry->project_id => $entry->incomeAmount()]
        )->all();

        $journalDate = $entries->first()?->journal_date ?? now();

        $deductions = $this->operationalDeductionService->distribute($projects, $incomes, $journalDate);

        foreach ($entries as $entry) {
            $isZeroActivity = $entry->incomeAmount() === 0.0 && $entry->expenseAmount() === 0.0;

            $entry->operational_deduction = $isZeroActivity
                ? 0.0
                : (float) ($deductions[$entry->project_id] ?? 0);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyDailyTotals(Collection $entries): Collection
    {
        foreach ($entries as $entry) {
            $entry->daily_total = $this->calculateDailyTotal(
                $entry->incomeAmount(),
                $entry->contributionAmount(),
                $entry->expenseAmount(),
                (float) $entry->administrative_fee,
                (float) $entry->operational_deduction,
            );
        }

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @param  array<int, array{fund_balance: float, accumulated_administrative_debt: float}>  $previousBalances
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyFundBalances(Collection $entries, array $previousBalances): Collection
    {
        foreach ($entries as $entry) {
            $previous = (float) ($previousBalances[$entry->project_id]['fund_balance'] ?? 0);
            $entry->fund_balance = $this->calculateFundBalance($previous, (float) $entry->daily_total);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @param  array<int, array{fund_balance: float, accumulated_administrative_debt: float, outstanding_project_administration: float}>  $previousBalances
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyAdministrativeExpenseCoverage(Collection $entries, array $previousBalances = []): Collection
    {
        foreach ($entries as $entry) {
            $previousOutstanding = round(
                (float) ($previousBalances[$entry->project_id]['outstanding_project_administration'] ?? 0),
                2
            );

            $isZeroActivity = $entry->incomeAmount() === 0.0 && $entry->expenseAmount() === 0.0;

            if ($isZeroActivity) {
                $expense = round(max(0, (float) $entry->administrative_expense), 2);

                $entry->uncovered_administrative_expense = $expense;
                $entry->project_administration_settled = 0.0;
                $entry->outstanding_project_administration = round($previousOutstanding + $expense, 2);

                continue;
            }

            $coverage = $this->calculateAdministrativeExpenseCoverage(
                (float) $entry->fund_balance,
                (float) $entry->administrative_expense,
            );

            $fundBalance = $coverage['fund_balance'];
            $uncovered = $coverage['uncovered'];

            $remainingSurplus = max(0.0, $fundBalance);
            $settled = round(min($remainingSurplus, $previousOutstanding), 2);
            $fundBalance = round($fundBalance - $settled, 2);
            $outstanding = round($previousOutstanding + $uncovered - $settled, 2);

            $entry->fund_balance = $fundBalance;
            $entry->uncovered_administrative_expense = $uncovered;
            $entry->project_administration_settled = $settled;
            $entry->outstanding_project_administration = $outstanding;
        }

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyAdministrativeDebt(Collection $entries): Collection
    {
        foreach ($entries as $entry) {
            $contribution = $entry->contributionAmount();

            $fundConsumptionDebt = $this->calculateAdministrativeDebt(
                round((float) $entry->fund_balance - $contribution, 2),
                (float) $entry->administrative_fee,
            );

            $entry->administrative_debt = round($fundConsumptionDebt + $contribution, 2);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @param  array<int, array{fund_balance: float, accumulated_administrative_debt: float}>  $previousBalances
     * @return Collection<int, DailyJournalEntry>
     */
    public function applyAccumulatedAdministrativeDebt(Collection $entries, array $previousBalances): Collection
    {
        foreach ($entries as $entry) {
            $priorDebt = (float) ($previousBalances[$entry->project_id]['accumulated_administrative_debt'] ?? 0);
            $entry->accumulated_administrative_debt = $this->calculateAccumulatedAdministrativeDebt(
                $priorDebt,
                (float) $entry->administrative_debt,
            );
        }

        return $entries;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{fund_balance: float, accumulated_administrative_debt: float, outstanding_project_administration: float}>
     */
    public function previousBalances(array $projectIds, CarbonInterface $date): array
    {
        if ($projectIds === []) {
            return [];
        }

        $dateString = $date->toDateString();

        $latestDates = DailyJournalEntry::query()
            ->selectRaw('project_id, MAX(journal_date) as journal_date')
            ->whereIn('project_id', $projectIds)
            ->whereDate('journal_date', '<', $dateString)
            ->groupBy('project_id');

        $rows = DailyJournalEntry::query()
            ->select([
                'daily_journal_entries.project_id',
                'daily_journal_entries.fund_balance',
                'daily_journal_entries.accumulated_administrative_debt',
                'daily_journal_entries.outstanding_project_administration',
                'daily_journal_entries.journal_date',
            ])
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('daily_journal_entries.project_id', '=', 'latest.project_id')
                    ->on('daily_journal_entries.journal_date', '=', 'latest.journal_date');
            })
            ->get()
            ->keyBy('project_id');

        $result = [];

        foreach ($projectIds as $projectId) {
            $row = $rows->get($projectId);
            $result[$projectId] = [
                'fund_balance' => (float) ($row?->fund_balance ?? 0),
                'accumulated_administrative_debt' => (float) ($row?->accumulated_administrative_debt ?? 0),
                'outstanding_project_administration' => (float) ($row?->outstanding_project_administration ?? 0),
            ];
        }

        return $result;
    }
}
