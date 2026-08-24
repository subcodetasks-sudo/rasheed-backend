<?php

namespace Modules\OperationalRate\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\OperationalFund\Actions\LoadOperationalExpensesByDateAction;
use Modules\Project\Enums\OperationalDeductionType;

class BuildOperationalRateAction
{
    public function __construct(
        private readonly LoadOperationalExpensesByDateAction $loadOperationalExpensesByDateAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();

        $dailyAggregates = $this->dailyAggregates($startOfMonth, $endOfMonth);
        $expenses = $this->loadOperationalExpensesByDateAction->execute(
            $startOfMonth->toDateString(),
            $endOfMonth->toDateString(),
        );
        $dailyRecords = $this->expandToCalendar($dailyAggregates, $expenses, $startOfMonth, $endOfMonth);
        $summary = $this->monthSummary($dailyAggregates, $expenses);
        $monthlyTotals = $this->monthlyTotals($dailyAggregates, $expenses);

        return [
            'summary' => $summary,
            'month' => ['month' => $month, 'year' => $year],
            'daily_records' => $dailyRecords,
            'monthly_totals' => $monthlyTotals,
        ];
    }

    /**
     * @return array<string, object{
     *     total_income: float|string,
     *     operational_deduction: float|string,
     *     relative_operational_deduction: float|string,
     *     fixed_operational_deduction: float|string,
     *     administrative_percentage: float|string
     * }>
     */
    private function dailyAggregates(CarbonInterface $start, CarbonInterface $end): array
    {
        $relative = OperationalDeductionType::Relative->value;
        $fixed = OperationalDeductionType::Fixed->value;

        $rows = DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->whereDate('daily_journal_entries.journal_date', '>=', $start->toDateString())
            ->whereDate('daily_journal_entries.journal_date', '<=', $end->toDateString())
            ->groupBy('daily_journal_entries.journal_date')
            ->selectRaw('daily_journal_entries.journal_date as date')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_income, 0)), 0) as total_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.operational_deduction, 0)), 0) as operational_deduction')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN projects.operational_deduction_type = \''.$relative.'\''
                .' THEN COALESCE(daily_journal_entries.operational_deduction, 0) ELSE 0 END), 0)'
                .' as relative_operational_deduction'
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN projects.operational_deduction_type = \''.$fixed.'\''
                .' THEN COALESCE(daily_journal_entries.operational_deduction, 0) ELSE 0 END), 0)'
                .' as fixed_operational_deduction'
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN projects.administrative_exempt = 0 THEN'
                .' COALESCE(daily_journal_entries.administrative_fee, 0)'
                .' - COALESCE(daily_journal_entries.administrative_debt, 0)'
                .' + COALESCE(daily_journal_entries.contribution, 0)'
                .' ELSE 0 END), 0) as administrative_percentage'
            )
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $dateKey = Carbon::parse($row->date)->toDateString();
            $keyed[$dateKey] = $row;
        }

        return $keyed;
    }

    /**
     * @param  array<string, object>  $aggregates
     * @param  array<string, float>  $expenses
     * @return list<array{date: string, day_name: string, total_income: string, operational_deduction: string, operational_expense: string, operational_net: string, administrative_percentage: string}>
     */
    private function expandToCalendar(
        array $aggregates,
        array $expenses,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $records = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateString = $cursor->toDateString();
            $row = $aggregates[$dateString] ?? null;
            $deduction = round((float) ($row->operational_deduction ?? 0), 2);
            $expense = round((float) ($expenses[$dateString] ?? 0), 2);

            $records[] = [
                'date' => $dateString,
                'day_name' => $cursor->format('l'),
                'total_income' => FormatMoneyDecimal::formatRounded($row->total_income ?? 0),
                'operational_deduction' => FormatMoneyDecimal::formatRounded($deduction),
                'operational_expense' => FormatMoneyDecimal::formatRounded($expense),
                'operational_net' => FormatMoneyDecimal::formatRounded(round($deduction - $expense, 2)),
                'administrative_percentage' => FormatMoneyDecimal::formatRounded($row->administrative_percentage ?? 0),
            ];

            $cursor->addDay();
        }

        return $records;
    }

    /**
     * @param  array<string, object>  $aggregates
     * @param  array<string, float>  $expenses
     * @return array{
     *     relative_operational_deduction: string,
     *     fixed_operational_deduction: string,
     *     total_operational_deduction: string,
     *     total_operational_expense: string,
     *     total_operational_net: string
     * }
     */
    private function monthSummary(array $aggregates, array $expenses): array
    {
        $relative = 0.0;
        $fixed = 0.0;

        foreach ($aggregates as $row) {
            $relative += (float) $row->relative_operational_deduction;
            $fixed += (float) $row->fixed_operational_deduction;
        }

        $relative = round($relative, 2);
        $fixed = round($fixed, 2);
        $totalDeduction = round($relative + $fixed, 2);
        $totalExpense = round(array_sum($expenses), 2);
        $totalNet = round($totalDeduction - $totalExpense, 2);

        return [
            'relative_operational_deduction' => FormatMoneyDecimal::formatRounded($relative),
            'fixed_operational_deduction' => FormatMoneyDecimal::formatRounded($fixed),
            'total_operational_deduction' => FormatMoneyDecimal::formatRounded($totalDeduction),
            'total_operational_expense' => FormatMoneyDecimal::formatRounded($totalExpense),
            'total_operational_net' => FormatMoneyDecimal::formatRounded($totalNet),
        ];
    }

    /**
     * @param  array<string, object>  $aggregates
     * @param  array<string, float>  $expenses
     * @return array{
     *     total_income: string,
     *     total_operational_deduction: string,
     *     total_operational_expense: string,
     *     total_operational_net: string,
     *     total_administrative_percentage: string
     * }
     */
    private function monthlyTotals(array $aggregates, array $expenses): array
    {
        $totalIncome = 0.0;
        $totalOd = 0.0;
        $totalAdmin = 0.0;

        foreach ($aggregates as $row) {
            $totalIncome += (float) $row->total_income;
            $totalOd += (float) $row->operational_deduction;
            $totalAdmin += (float) $row->administrative_percentage;
        }

        $totalOd = round($totalOd, 2);
        $totalExpense = round(array_sum($expenses), 2);

        return [
            'total_income' => FormatMoneyDecimal::formatRounded($totalIncome),
            'total_operational_deduction' => FormatMoneyDecimal::formatRounded($totalOd),
            'total_operational_expense' => FormatMoneyDecimal::formatRounded($totalExpense),
            'total_operational_net' => FormatMoneyDecimal::formatRounded(round($totalOd - $totalExpense, 2)),
            'total_administrative_percentage' => FormatMoneyDecimal::formatRounded($totalAdmin),
        ];
    }
}
