<?php

namespace Modules\OperationalFund\Actions;

use Carbon\Carbon;
use Modules\OperationalFund\Enums\DayStatus;
use Modules\OperationalFund\Enums\DeficitCoverageStatus;

class BuildOperationalFundMonthAction
{
    public function __construct(
        private readonly ResolveExpectedOperationalIncomeAction $resolveExpectedOperationalIncomeAction,
        private readonly LoadOperationalExpensesByDateAction $loadOperationalExpensesByDateAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $start = $startOfMonth->toDateString();
        $end = $endOfMonth->toDateString();

        $expenses = $this->loadOperationalExpensesByDateAction->execute($start, $end);

        $days = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        $accum = 0.0;

        $cursor = $startOfMonth->copy();
        while ($cursor->lte($endOfMonth)) {
            $dateString = $cursor->toDateString();
            $income = $this->resolveExpectedOperationalIncomeAction->execute($dateString);
            $expense = round((float) ($expenses[$dateString] ?? 0), 2);
            $net = round($income - $expense, 2);

            [$status, $coverage, $accum] = $this->applyDayToAccumulator($net, $accum);

            $days[] = [
                'date' => $dateString,
                'operational_income' => $this->decimal($income),
                'operational_expense' => $this->decimal($expense),
                'daily_net' => $this->decimal($net),
                'day_status' => $status->value,
                'deficit_coverage_status' => $coverage?->value,
            ];

            $totalIncome += $income;
            $totalExpense += $expense;
            $cursor->addDay();
        }

        $totalIncome = round($totalIncome, 2);
        $totalExpense = round($totalExpense, 2);
        $monthlyNet = round($totalIncome - $totalExpense, 2);

        return [
            'month' => $month,
            'year' => $year,
            'summary' => [
                'total_monthly_operational_income' => $this->decimal($totalIncome),
                'total_monthly_operational_expense' => $this->decimal($totalExpense),
                'monthly_operational_net' => $this->decimal($monthlyNet),
            ],
            'days' => $days,
        ];
    }

    /**
     * @return array{0: DayStatus, 1: DeficitCoverageStatus|null, 2: float}
     */
    private function applyDayToAccumulator(float $net, float $accum): array
    {
        if ($net > 0) {
            return [DayStatus::OperationalSurplus, null, round($accum + $net, 2)];
        }

        if ($net < 0) {
            $need = round(abs($net), 2);
            if ($accum >= $need) {
                return [DayStatus::OperationalDeficit, DeficitCoverageStatus::Covered, round($accum - $need, 2)];
            }

            return [DayStatus::OperationalDeficit, DeficitCoverageStatus::NotCovered, 0.0];
        }

        return [DayStatus::Balanced, null, $accum];
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
