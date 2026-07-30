<?php

namespace Modules\AdministrationRates\Actions;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;

class BuildAdministrationRatesAction
{
    public function __construct(
        private readonly ResolveAdminFeePercentageAction $resolveAdminFeePercentageAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        $percentage = $this->resolveAdminFeePercentageAction->execute();

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();

        $summary = $this->allTimeSummary();
        $dailyAggregates = $this->dailyAggregates($startOfMonth, $endOfMonth);
        $dailyRecords = $this->expandToCalendar($dailyAggregates, $startOfMonth, $endOfMonth);
        $monthlyTotals = $this->monthlyTotals($dailyAggregates);

        return [
            'administrative_percentage' => $percentage,
            'summary' => $summary,
            'month' => ['month' => $month, 'year' => $year],
            'daily_records' => $dailyRecords,
            'monthly_totals' => $monthlyTotals,
        ];
    }

    private function eligibleBaseQuery(): Builder
    {
        return DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->where('projects.administrative_exempt', false);
    }

    private function allTimeSummary(): array
    {
        $row = $this->eligibleBaseQuery()
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_income, 0)), 0) as total_institution_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.administrative_fee, 0)), 0) as total_administrative_percentage')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.administrative_debt, 0)), 0) as total_administrative_debt')
            ->first();

        return [
            'total_institution_income' => $this->decimal($row->total_institution_income),
            'total_administrative_percentage' => $this->decimal($row->total_administrative_percentage),
            'total_administrative_debt' => $this->decimal($row->total_administrative_debt),
        ];
    }

    /**
     * @return array<string, object{total_income: float, administrative_percentage: float, administrative_debt: float}>
     */
    private function dailyAggregates(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = $this->eligibleBaseQuery()
            ->whereDate('daily_journal_entries.journal_date', '>=', $start->toDateString())
            ->whereDate('daily_journal_entries.journal_date', '<=', $end->toDateString())
            ->groupBy('daily_journal_entries.journal_date')
            ->selectRaw('daily_journal_entries.journal_date as date')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_income, 0)), 0) as total_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.administrative_fee, 0)), 0) as administrative_percentage')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.administrative_debt, 0)), 0) as administrative_debt')
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $dateKey = Carbon::parse($row->date)->toDateString();
            $keyed[$dateKey] = $row;
        }

        return $keyed;
    }

    private function expandToCalendar(array $aggregates, CarbonInterface $start, CarbonInterface $end): array
    {
        $records = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateString = $cursor->toDateString();
            $row = $aggregates[$dateString] ?? null;

            $records[] = [
                'date' => $dateString,
                'total_income' => $this->decimal($row->total_income ?? 0),
                'administrative_percentage' => $this->decimal($row->administrative_percentage ?? 0),
                'administrative_debt' => $this->decimal($row->administrative_debt ?? 0),
            ];

            $cursor->addDay();
        }

        return $records;
    }

    private function monthlyTotals(array $aggregates): array
    {
        $totalIncome = 0.0;
        $totalFee = 0.0;
        $totalDebt = 0.0;

        foreach ($aggregates as $row) {
            $totalIncome += (float) $row->total_income;
            $totalFee += (float) $row->administrative_percentage;
            $totalDebt += (float) $row->administrative_debt;
        }

        return [
            'month_total_income' => $this->decimal($totalIncome),
            'month_total_administrative_percentage' => $this->decimal($totalFee),
            'month_total_administrative_debt' => $this->decimal($totalDebt),
        ];
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
