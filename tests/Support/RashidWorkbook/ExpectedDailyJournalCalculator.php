<?php

namespace Tests\Support\RashidWorkbook;

/**
 * Pure-PHP, DB-free reimplementation of Modules/DailyJournal/EQUATIONS.md's
 * per-day formulas, computed directly from raw income/expense - never copied
 * from the workbook's own (whole-currency-unit-rounded) cells. Mirrors the
 * exact rounding order in
 * Modules\DailyJournal\Services\DailyJournalCalculationService and
 * Modules\Project\Services\{AdministrativeDeductionService,OperationalDeductionService}.
 *
 * Administrative_expense is always 0.0 for the Rashid workbook dataset (every
 * outgoing inventory movement in it is operational-typed, never
 * administrative-typed) - kept as an explicit literal below rather than
 * omitted, so the formula shape stays self-documenting.
 */
class ExpectedDailyJournalCalculator
{
    private const OPERATIONAL_DEDUCTION_POOL = 1081.0;

    /** @var array<string, float> project name => running fund balance */
    private array $fundBalance = [];

    /** @var array<string, float> project name => running accumulated administrative debt */
    private array $accumulatedDebt = [];

    /**
     * @param  array<string, array{operational_deduction_type: string, operational_fixed_amount: ?float, administrative_exempt: bool, administrative_fee_percentage: float}>  $projectDefs
     * @param  array<string, array{income: float, expense: float}>  $incomeExpenseForDay
     * @return array<string, array{daily_income: float, daily_expense: float, administrative_fee: float, operational_deduction: float, administrative_expense: float, daily_total: float, fund_balance: float, administrative_debt: float, accumulated_administrative_debt: float}>
     */
    public function computeDay(array $projectDefs, array $incomeExpenseForDay): array
    {
        $relativeIncomeTotal = 0.0;
        foreach ($projectDefs as $name => $def) {
            if ($def['operational_deduction_type'] === 'relative') {
                $relativeIncomeTotal += $incomeExpenseForDay[$name]['income'];
            }
        }

        $result = [];

        foreach ($projectDefs as $name => $def) {
            $income = (float) $incomeExpenseForDay[$name]['income'];
            $expense = (float) $incomeExpenseForDay[$name]['expense'];

            $administrativeFee = $def['administrative_exempt']
                ? 0.0
                : round($income * ($def['administrative_fee_percentage'] / 100), 2);

            $operationalDeduction = match ($def['operational_deduction_type']) {
                'relative' => $relativeIncomeTotal > 0
                    ? round(($income / $relativeIncomeTotal) * self::OPERATIONAL_DEDUCTION_POOL, 2)
                    : 0.0,
                'fixed' => (float) $def['operational_fixed_amount'],
                'exempt' => 0.0,
            };

            $administrativeExpense = 0.0;

            $dailyTotal = round(
                $income + 0.0 - $expense - $administrativeExpense - $administrativeFee - $operationalDeduction,
                2
            );

            $previousFundBalance = $this->fundBalance[$name] ?? 0.0;
            $fundBalance = round($previousFundBalance + $dailyTotal, 2);

            // Expense-first fund consumption + contribution (workbook contribution is always 0).
            $contribution = 0.0;
            $balanceBeforeContribution = round($fundBalance - $contribution, 2);
            $expenseConsumed = min($administrativeExpense, $administrativeFee);
            $remainingFee = round($administrativeFee - $expenseConsumed, 2);
            $expenseDebt = $administrativeExpense > $administrativeFee ? $expenseConsumed : 0.0;
            $deficitDebt = $balanceBeforeContribution < 0
                ? min(abs($balanceBeforeContribution), $remainingFee)
                : 0.0;
            $administrativeDebt = round($expenseDebt + $deficitDebt + $contribution, 2);

            $previousAccumulatedDebt = $this->accumulatedDebt[$name] ?? 0.0;
            $accumulatedDebt = round($previousAccumulatedDebt + $administrativeDebt, 2);

            $this->fundBalance[$name] = $fundBalance;
            $this->accumulatedDebt[$name] = $accumulatedDebt;

            $result[$name] = [
                'daily_income' => $income,
                'daily_expense' => $expense,
                'administrative_fee' => $administrativeFee,
                'operational_deduction' => $operationalDeduction,
                'administrative_expense' => $administrativeExpense,
                'daily_total' => $dailyTotal,
                'fund_balance' => $fundBalance,
                'administrative_debt' => $administrativeDebt,
                'accumulated_administrative_debt' => $accumulatedDebt,
            ];
        }

        return $result;
    }
}
