<?php

namespace Modules\OperationalFund\Actions;

use Modules\OperationalFund\Models\OperationalFundDay;

class LoadOperationalExpensesByDateAction
{
    /**
     * @return array<string, float> date string => expense
     */
    public function execute(string $startDate, string $endDate): array
    {
        $rows = OperationalFundDay::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->get(['date', 'operational_expense']);

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->date->toDateString()] = round((float) $row->operational_expense, 2);
        }

        return $keyed;
    }

    public function forDate(string $date): float
    {
        $row = OperationalFundDay::query()->whereDate('date', $date)->first();

        return round((float) ($row?->operational_expense ?? 0), 2);
    }
}
