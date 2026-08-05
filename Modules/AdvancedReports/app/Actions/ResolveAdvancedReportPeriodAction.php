<?php

namespace Modules\AdvancedReports\Actions;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class ResolveAdvancedReportPeriodAction
{
    /**
     * Last N calendar months ending at the current month (inclusive).
     *
     * @return list<array{year: int, month: int, label: string, start_date: string, end_date: string, end_bound: string}>
     */
    public function execute(int $period, ?CarbonInterface $asOf = null): array
    {
        $cursor = ($asOf ?? Carbon::now())->copy()->startOfMonth();
        $months = [];

        for ($i = $period - 1; $i >= 0; $i--) {
            $monthStart = $cursor->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $months[] = [
                'year' => (int) $monthStart->year,
                'month' => (int) $monthStart->month,
                'label' => $monthStart->format('M Y'),
                'start_date' => $monthStart->toDateString(),
                'end_date' => $monthEnd->toDateString(),
                'end_bound' => $monthEnd->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ];
        }

        return $months;
    }

    /**
     * @return array{start_date: string, end_date: string, end_bound: string}
     */
    public function dateRange(int $period, ?CarbonInterface $asOf = null): array
    {
        $months = $this->execute($period, $asOf);
        $first = $months[0];
        $last = $months[array_key_last($months)];

        return [
            'start_date' => $first['start_date'],
            'end_date' => $last['end_date'],
            'end_bound' => $last['end_bound'],
        ];
    }
}
