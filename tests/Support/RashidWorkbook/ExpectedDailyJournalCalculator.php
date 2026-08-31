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
    /** Employee-cost budget for the day (Monthly Employee Settings category sum) — fixed-type
     *  projects' deductions are a carve-out of this total, not an addition on top of it. */
    private const EMPLOYEE_TOTAL_POOL = 1081.0;

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
        $fixedSum = 0.0;
        foreach ($projectDefs as $name => $def) {
            if ($def['operational_deduction_type'] === 'relative') {
                $relativeIncomeTotal += $incomeExpenseForDay[$name]['income'];
            } elseif ($def['operational_deduction_type'] === 'fixed') {
                $fixedSum += (float) $def['operational_fixed_amount'];
            }
        }

        $relativePool = round(max(0, self::EMPLOYEE_TOTAL_POOL - $fixedSum), 2);

        $result = [];

        foreach ($projectDefs as $name => $def) {
            $income = (float) $incomeExpenseForDay[$name]['income'];
            $expense = (float) $incomeExpenseForDay[$name]['expense'];

            $administrativeFee = $def['administrative_exempt']
                ? 0.0
                : round($income * ($def['administrative_fee_percentage'] / 100), 2);

            $isZeroActivity = $income === 0.0 && $expense === 0.0;

            $operationalDeduction = $isZeroActivity
                ? 0.0
                : match ($def['operational_deduction_type']) {
                    'relative' => $relativeIncomeTotal > 0
                        ? round(($income / $relativeIncomeTotal) * $relativePool, 2)
                        : 0.0,
                    'fixed' => (float) $def['operational_fixed_amount'],
                    'exempt' => 0.0,
                };

            $administrativeExpense = 0.0;

            $dailyTotal = round(
                $income + 0.0 - $expense - $administrativeFee - $operationalDeduction,
                2
            );

            $previousFundBalance = $this->fundBalance[$name] ?? 0.0;
            $todayBalance = round($dailyTotal, 2);

            if (! $isZeroActivity) {
                $surplus = max(0, $todayBalance);
                $covered = round(min($surplus, $administrativeExpense), 2);
                $todayBalance = round($todayBalance - $covered, 2);
            }

            $contribution = 0.0;
            $balanceBeforeContribution = round($todayBalance - $contribution, 2);
            $deficitDebt = $balanceBeforeContribution < 0
                ? min(abs($balanceBeforeContribution), $administrativeFee)
                : 0.0;
            $administrativeDebt = round($deficitDebt + $contribution, 2);
            $todayBalance = round($todayBalance + $deficitDebt, 2);

            $result[$name] = [
                'daily_income' => $income,
                'daily_expense' => $expense,
                'administrative_fee' => $administrativeFee,
                'operational_deduction' => $operationalDeduction,
                'administrative_expense' => $administrativeExpense,
                'daily_total' => $dailyTotal,
                'fund_balance' => $todayBalance,
                'administrative_debt' => $administrativeDebt,
                'remaining_fee' => round(max(0, $administrativeFee - $deficitDebt), 2),
                'previous_fund_balance' => $previousFundBalance,
            ];
        }

        $result = $this->applyAdministrativePoolRelief($result);

        foreach ($result as $name => $row) {
            $fundBalance = round($row['previous_fund_balance'] + $row['fund_balance'], 2);
            $result[$name]['fund_balance'] = $fundBalance;

            $previousAccumulatedDebt = $this->accumulatedDebt[$name] ?? 0.0;
            $accumulatedDebt = round($previousAccumulatedDebt + $row['administrative_debt'], 2);

            $result[$name]['accumulated_administrative_debt'] = $accumulatedDebt;

            $this->fundBalance[$name] = $fundBalance;
            $this->accumulatedDebt[$name] = $accumulatedDebt;
        }

        return $result;
    }

    /**
     * Same-day Administrative Percentage Pool relief (Stage 3, EQUATIONS.md §6a): a project still in
     * deficit after Case 1 draws from OTHER projects' unused same-day fee only when it also has a
     * same-day operating deficit (`daily_total < 0`). Carried-only deficits are never relieved.
     *
     * @param  array<string, array{fund_balance: float, administrative_debt: float, remaining_fee: float, daily_total: float}>  $result
     * @return array<string, array{fund_balance: float, administrative_debt: float, remaining_fee: float, daily_total: float}>
     */
    private function applyAdministrativePoolRelief(array $result): array
    {
        $sourceCapacity = [];
        foreach ($result as $name => $row) {
            $sourceCapacity[$name] = $row['remaining_fee'];
        }

        $beneficiaryNames = array_keys(array_filter(
            $result,
            fn (array $row) => $row['daily_total'] < 0 && $row['fund_balance'] < 0,
        ));

        usort($beneficiaryNames, function (string $a, string $b) use ($result) {
            $order = array_flip(array_keys($result));

            return $order[$a] <=> $order[$b];
        });

        $sourceNames = array_keys($result);

        foreach ($beneficiaryNames as $beneficiaryName) {
            $need = round(abs(min(0, $result[$beneficiaryName]['fund_balance'])), 2);

            if ($need <= 0) {
                continue;
            }

            foreach ($sourceNames as $sourceName) {
                if ($need <= 0) {
                    break;
                }

                if ($sourceName === $beneficiaryName) {
                    continue;
                }

                $available = $sourceCapacity[$sourceName] ?? 0.0;
                $used = round(min($available, $need), 2);

                if ($used <= 0) {
                    continue;
                }

                $sourceCapacity[$sourceName] = round($available - $used, 2);
                $need = round($need - $used, 2);

                $result[$beneficiaryName]['fund_balance'] = round($result[$beneficiaryName]['fund_balance'] + $used, 2);
                $result[$beneficiaryName]['administrative_debt'] = round(
                    $result[$beneficiaryName]['administrative_debt'] + $used,
                    2
                );
            }
        }

        return $result;
    }
}
