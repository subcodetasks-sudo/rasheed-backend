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
     * Daily Total = income + contribution - expense - admin expense - admin fee - operational deduction
     */
    public function calculateDailyTotal(
        float $income,
        float $contribution,
        float $expense,
        float $administrativeExpense,
        float $administrativeFee,
        float $operationalDeduction,
    ): float {
        return round(
            $income
            + $contribution
            - $expense
            - $administrativeExpense
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
     * Administrative Fund consumption debt (Cases 1 and 2 only).
     *
     * Expense-first allocation:
     * - expense_consumed = min(expense, fee)
     * - remaining_fee = fee - expense_consumed
     * - Case 2 debt when expense > fee: expense_consumed
     * - Case 1 debt when fund_balance < 0: min(|fund_balance|, remaining_fee)
     *
     * Does not modify fund_balance. Contribution is added separately in applyAdministrativeDebt.
     */
    public function calculateAdministrativeDebt(
        float $fundBalance,
        float $administrativeFee,
        float $administrativeExpense,
    ): float {
        $fee = round(max(0, $administrativeFee), 2);
        $expense = round(max(0, $administrativeExpense), 2);
        $balance = round($fundBalance, 2);

        $expenseConsumed = min($expense, $fee);
        $remainingFee = round($fee - $expenseConsumed, 2);

        $expenseDebt = $expense > $fee ? $expenseConsumed : 0.0;
        $deficitDebt = $balance < 0
            ? min(abs($balance), $remainingFee)
            : 0.0;

        return round($expenseDebt + $deficitDebt, 2);
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
     * Priority (stop when surplus is exhausted):
     * 1. Cover remaining fund balance deficit (if any).
     * 2. Repay today's administrative debt.
     * 3. Repay accumulated administrative debt.
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

        // Priority 1: cover remaining fund balance deficit if any remains.
        // With available surplus required by the caller, fund_balance is expected to be > 0,
        // so this branch is typically a no-op.
        if ($fundBalance < 0) {
            return [
                'fund_balance' => $fundBalance,
                'administrative_debt' => $administrativeDebt,
                'accumulated_administrative_debt' => $accumulatedAdministrativeDebt,
            ];
        }

        $surplus = $fundBalance;

        // Priority 2: repay today's administrative debt.
        $repayToday = min($surplus, $administrativeDebt);
        $administrativeDebt = round($administrativeDebt - $repayToday, 2);
        $surplus = round($surplus - $repayToday, 2);
        $accumulatedAdministrativeDebt = round(max(0, $accumulatedAdministrativeDebt - $repayToday), 2);

        // Priority 3: repay accumulated administrative debt.
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
            $entry->operational_deduction = (float) ($deductions[$entry->project_id] ?? 0);
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
                (float) $entry->administrative_expense,
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
     * @return Collection<int, DailyJournalEntry>
     */
    /**
     * Today's Administrative Debt = fund-consumption debt (Cases 1/2) + contribution.
     *
     * Fund-consumption base uses the pre-contribution fund balance so re-saving a
     * different contribution recomputes from the same base (never compounds).
     *
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
                (float) $entry->administrative_expense,
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
     * Latest cumulative values for each project before the given journal date.
     *
     * Fetches only one prior row per project via MAX(journal_date) subquery.
     * Safe without secondary ordering because unique(project_id, journal_date)
     * guarantees a single entry per project per day.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array{fund_balance: float, accumulated_administrative_debt: float}>
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
            ];
        }

        return $result;
    }
}
