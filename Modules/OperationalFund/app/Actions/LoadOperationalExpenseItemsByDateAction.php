<?php

namespace Modules\OperationalFund\Actions;

use Carbon\Carbon;
use Modules\OperationalFund\Models\OperationalFundExpenseItem;

class LoadOperationalExpenseItemsByDateAction
{
    /**
     * Expense line items grouped by their day, ordered oldest date first and, within a
     * day, by insertion order.
     *
     * @return array<string, list<array{amount: float, description: string}>>
     */
    public function execute(string $startDate, string $endDate): array
    {
        $rows = OperationalFundExpenseItem::query()
            ->join(
                'operational_fund_days',
                'operational_fund_days.id',
                '=',
                'operational_fund_expense_items.operational_fund_day_id',
            )
            ->whereDate('operational_fund_days.date', '>=', $startDate)
            ->whereDate('operational_fund_days.date', '<=', $endDate)
            ->orderBy('operational_fund_days.date')
            ->orderBy('operational_fund_expense_items.id')
            ->get([
                'operational_fund_days.date as day_date',
                'operational_fund_expense_items.amount',
                'operational_fund_expense_items.description',
            ]);

        $keyed = [];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->day_date)->toDateString();
            $keyed[$key][] = [
                'amount' => round((float) $row->amount, 2),
                'description' => (string) $row->description,
            ];
        }

        return $keyed;
    }

    /**
     * @return list<array{amount: float, description: string}>
     */
    public function forDate(string $date): array
    {
        $key = Carbon::parse($date)->toDateString();

        return $this->execute($key, $key)[$key] ?? [];
    }
}
