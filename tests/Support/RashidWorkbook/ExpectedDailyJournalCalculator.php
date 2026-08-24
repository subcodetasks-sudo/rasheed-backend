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
                $income + 0.0 - $expense - $administrativeFee - $operationalDeduction,
                2
            );

            $previousFundBalance = $this->fundBalance[$name] ?? 0.0;
            $intermediateFundBalance = round($previousFundBalance + $dailyTotal, 2);

            $surplus = max(0, $intermediateFundBalance);
            $covered = round(min($surplus, $administrativeExpense), 2);
            $fundBalance = round($intermediateFundBalance - $covered, 2);

            $contribution = 0.0;
            $balanceBeforeContribution = round($fundBalance - $contribution, 2);
            $deficitDebt = $balanceBeforeContribution < 0
                ? min(abs($balanceBeforeContribution), $administrativeFee)
                : 0.0;
            $administrativeDebt = round($deficitDebt + $contribution, 2);
            $fundBalance = round($fundBalance + $deficitDebt, 2);

            $result[$name] = [
                'daily_income' => $income,
                'daily_expense' => $expense,
                'administrative_fee' => $administrativeFee,
                'operational_deduction' => $operationalDeduction,
                'administrative_expense' => $administrativeExpense,
                'daily_total' => $dailyTotal,
                'fund_balance' => $fundBalance,
                'administrative_debt' => $administrativeDebt,
                'remaining_fee' => round(max(0, $administrativeFee - $deficitDebt), 2),
            ];
        }

        $result = $this->applyAdministrativePoolRelief($result);

        foreach ($result as $name => $row) {
            $previousAccumulatedDebt = $this->accumulatedDebt[$name] ?? 0.0;
            $accumulatedDebt = round($previousAccumulatedDebt + $row['administrative_debt'], 2);

            $result[$name]['accumulated_administrative_debt'] = $accumulatedDebt;

            $this->fundBalance[$name] = $row['fund_balance'];
            $this->accumulatedDebt[$name] = $accumulatedDebt;
        }

        return $result;
    }

    /**
     * Same-day Administrative Percentage Pool relief (Stage 3, EQUATIONS.md §6a): a project still in
     * deficit after Case 1 draws from OTHER projects' unused same-day fee only. Beneficiaries and
     * sources are both walked in $projectDefs iteration order, which mirrors the app's project_id
     * ascending order because WorkbookScenarioBuilder::seedProjects() creates projects in that same
     * array order. Exactly like Case 1, the amount drawn is recorded as administrative_debt (which then
     * flows into accumulated_administrative_debt) and added onto the beneficiary's fund_balance - the
     * only difference from Case 1 is the funding source (another project's unused same-day fee, not the
     * project's own). It is a real, zero-sum transfer: the source's fund_balance is reduced by the same
     * amount, so the org-wide total is unaffected.
     *
     * @param  array<string, array{fund_balance: float, administrative_debt: float, remaining_fee: float}>  $result
     * @return array<string, array{fund_balance: float, administrative_debt: float, remaining_fee: float}>
     */
    private function applyAdministrativePoolRelief(array $result): array
    {
        $sourceCapacity = [];
        foreach ($result as $name => $row) {
            $sourceCapacity[$name] = $row['remaining_fee'];
        }

        foreach ($result as $beneficiaryName => $beneficiaryRow) {
            $need = round(abs(min(0, $beneficiaryRow['fund_balance'])), 2);

            if ($need <= 0) {
                continue;
            }

            foreach ($result as $sourceName => $sourceRow) {
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
                $result[$sourceName]['fund_balance'] = round($result[$sourceName]['fund_balance'] - $used, 2);
            }
        }

        return $result;
    }
}
