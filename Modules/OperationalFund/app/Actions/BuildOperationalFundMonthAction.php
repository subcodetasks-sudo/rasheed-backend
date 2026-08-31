<?php

namespace Modules\OperationalFund\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Modules\OperationalFund\Enums\DayStatus;
use Modules\OperationalFund\Enums\DeficitCoverageStatus;

class BuildOperationalFundMonthAction
{
    public function __construct(
        private readonly ResolveExpectedOperationalIncomeAction $resolveExpectedOperationalIncomeAction,
        private readonly LoadOperationalExpensesByDateAction $loadOperationalExpensesByDateAction,
        private readonly LoadOperationalExpenseItemsByDateAction $loadOperationalExpenseItemsByDateAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $start = $startOfMonth->toDateString();
        $end = $endOfMonth->toDateString();

        $expenses = $this->loadOperationalExpensesByDateAction->execute($start, $end);
        $itemsByDate = $this->loadOperationalExpenseItemsByDateAction->execute($start, $end);
        ksort($itemsByDate);

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
                'operational_income' => FormatMoneyDecimal::formatRounded($income),
                'operational_expense' => FormatMoneyDecimal::formatRounded($expense),
                'daily_net' => FormatMoneyDecimal::formatRounded($net),
                'day_status' => $status->value,
                'deficit_coverage_status' => $coverage?->value,
                'expenses' => $this->formatItems($itemsByDate[$dateString] ?? []),
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
                'total_monthly_operational_income' => FormatMoneyDecimal::formatRounded($totalIncome),
                'total_monthly_operational_expense' => FormatMoneyDecimal::formatRounded($totalExpense),
                'monthly_operational_net' => FormatMoneyDecimal::formatRounded($monthlyNet),
            ],
            'days' => $days,
            'expense_items' => $this->flattenExpenseItems($itemsByDate),
        ];
    }

    /**
     * Flat, date-ordered list of every expense line item in the month — the bottom-of-page
     * breakdown table (كل صادر وقيمته وفي شو انصرف).
     *
     * @param  array<string, list<array{amount: float, description: string}>>  $itemsByDate
     * @return list<array{date: string, amount: string, description: string}>
     */
    private function flattenExpenseItems(array $itemsByDate): array
    {
        $flat = [];
        foreach ($itemsByDate as $date => $items) {
            foreach ($items as $item) {
                $flat[] = [
                    'date' => $date,
                    'amount' => FormatMoneyDecimal::formatRounded($item['amount']),
                    'description' => $item['description'],
                ];
            }
        }

        return $flat;
    }

    /**
     * @param  list<array{amount: float, description: string}>  $items
     * @return list<array{amount: string, description: string}>
     */
    private function formatItems(array $items): array
    {
        return array_map(fn (array $item): array => [
            'amount' => FormatMoneyDecimal::formatRounded($item['amount']),
            'description' => $item['description'],
        ], $items);
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
}
