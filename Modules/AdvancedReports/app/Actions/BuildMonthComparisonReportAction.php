<?php

namespace Modules\AdvancedReports\Actions;

use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\Project\Models\Project;

class BuildMonthComparisonReportAction
{
    public function __construct(
        private readonly ResolveAdvancedReportPeriodAction $resolveAdvancedReportPeriodAction,
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function execute(int $period): array
    {
        $months = $this->resolveAdvancedReportPeriodAction->execute($period);

        $projectIds = Project::query()
            ->active()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $trend = [];
        $adminVsOp = [];
        $table = [];
        $totalRevenue = 0.0;
        $totalExpenses = 0.0;
        $previousNet = null;

        foreach ($months as $monthMeta) {
            $aggregates = $this->buildCashStationAction->monthlyAggregatesByProject(
                $projectIds,
                $monthMeta['start_date'],
                $monthMeta['end_bound'],
            );

            $revenue = 0.0;
            $expenses = 0.0;
            $administrative = 0.0;
            $operational = 0.0;

            foreach ($aggregates as $aggregate) {
                $revenue += (float) ($aggregate->monthly_revenue ?? 0);
                $expenses += (float) ($aggregate->monthly_expenses ?? 0);
                $administrative += (float) ($aggregate->administrative_percentage ?? 0);
                $operational += (float) ($aggregate->operational_deduction ?? 0);
            }

            $revenue = round($revenue, 2);
            $expenses = round($expenses, 2);
            $administrative = round($administrative, 2);
            $operational = round($operational, 2);
            $net = round($revenue - $expenses, 2);

            $growthRate = null;
            if ($previousNet !== null && abs($previousNet) > 0.00001) {
                $growthRate = round((($net - $previousNet) / $previousNet) * 100, 2);
            }

            $trend[] = [
                'year' => $monthMeta['year'],
                'month' => $monthMeta['month'],
                'revenue' => $this->decimal($revenue),
                'expenses' => $this->decimal($expenses),
                'net' => $this->decimal($net),
            ];

            $adminVsOp[] = [
                'year' => $monthMeta['year'],
                'month' => $monthMeta['month'],
                'administrative_deduction' => $this->decimal($administrative),
                'operational_deduction' => $this->decimal($operational),
            ];

            $table[] = [
                'year' => $monthMeta['year'],
                'month' => $monthMeta['month'],
                'label' => $monthMeta['label'],
                'total_revenue' => $this->decimal($revenue),
                'expenses' => $this->decimal($expenses),
                'administrative_deduction' => $this->decimal($administrative),
                'operational_deduction' => $this->decimal($operational),
                'net' => $this->decimal($net),
                'growth_rate_percent' => $growthRate,
            ];

            $totalRevenue += $revenue;
            $totalExpenses += $expenses;
            $previousNet = $net;
        }

        $totalRevenue = round($totalRevenue, 2);
        $totalExpenses = round($totalExpenses, 2);
        $netPeriod = round($totalRevenue - $totalExpenses, 2);

        return [
            'report_type' => 'month_comparison',
            'period' => $period,
            'months' => array_map(fn (array $m) => [
                'year' => $m['year'],
                'month' => $m['month'],
                'label' => $m['label'],
            ], $months),
            'summary' => [
                'total_revenue' => $this->decimal($totalRevenue),
                'total_expenses' => $this->decimal($totalExpenses),
                'net_period' => $this->decimal($netPeriod),
            ],
            'charts' => [
                'revenue_expense_trend' => $trend,
                'administrative_vs_operational' => $adminVsOp,
            ],
            'comparison_table' => $table,
        ];
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
