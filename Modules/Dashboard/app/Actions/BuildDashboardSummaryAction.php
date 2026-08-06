<?php

namespace Modules\Dashboard\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\AdministrationRates\Actions\BuildAdministrationRatesAction;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\Inventory\Models\InventoryItem;

class BuildDashboardSummaryAction
{
    public function __construct(
        private readonly BuildAdministrationRatesAction $buildAdministrationRatesAction,
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function execute(CarbonInterface $journalDate): array
    {
        $dateString = $journalDate->toDateString();
        $month = (int) $journalDate->month;
        $year = (int) $journalDate->year;
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay()->toDateString();
        $endOfMonth = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay()->toDateString();

        $allProjects = DB::table('daily_journal_entries')
            ->whereDate('journal_date', $dateString)
            ->selectRaw('COALESCE(SUM(COALESCE(daily_income, 0)), 0) as total_daily_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_expense, 0)), 0) as total_daily_expenses')
            ->selectRaw('COALESCE(SUM(COALESCE(operational_deduction, 0)), 0) as total_operational_deduction')
            ->first();

        $administrativePercentage = $this->buildAdministrationRatesAction
            ->administrativePercentageForDate($journalDate);

        $lowStockItems = InventoryItem::query()
            ->whereColumn('current_balance', '<=', 'minimum_stock_level')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'current_balance', 'minimum_stock_level', 'project_id'])
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'current_balance' => $item->current_balance,
                'minimum_stock_level' => $item->minimum_stock_level,
                'project_id' => $item->project_id,
            ])
            ->values()
            ->all();

        $dailyMovement = $this->dailyMovementForMonth($startOfMonth, $endOfMonth);
        $monthlyTotals = $this->monthlyTotalsFromDailyRows($dailyMovement);

        $cashStationList = [];
        $cashStationStatus = [
            'total_projects' => 0,
            'surplus_projects_count' => 0,
            'deficit_projects_count' => 0,
            'projects_with_administrative_debt_count' => 0,
        ];

        // Empty month → empty Cash Station section; otherwise reuse Cash Station source of truth.
        if ($dailyMovement !== []) {
            $cashStation = $this->buildCashStationAction->execute($month, $year);
            $cashStationList = $cashStation['projects'] ?? [];
            $cashStationStatus = $this->cashStationStatus($cashStationList);
        }

        return [
            'total_daily_income' => FormatMoneyDecimal::format($allProjects->total_daily_income ?? 0),
            'total_daily_expenses' => FormatMoneyDecimal::format($allProjects->total_daily_expenses ?? 0),
            'total_administrative_percentage' => $administrativePercentage,
            'total_operational_deduction' => FormatMoneyDecimal::format($allProjects->total_operational_deduction ?? 0),
            'low_stock_items' => $lowStockItems,
            'cash_movement' => [
                'days' => $this->lastNDays($dailyMovement, 7, includeNet: false),
                'monthly_totals' => $monthlyTotals,
            ],
            'cash_station_status' => $cashStationStatus,
            'cash_station_list' => $cashStationList,
            'recent_activity' => $this->lastNDays($dailyMovement, 5, includeNet: true),
        ];
    }

    /**
     * @return list<object{date: string, total_income: float|string, total_expense: float|string}>
     */
    private function dailyMovementForMonth(string $startOfMonth, string $endOfMonth): array
    {
        return DB::table('daily_journal_entries')
            ->whereDate('journal_date', '>=', $startOfMonth)
            ->whereDate('journal_date', '<=', $endOfMonth)
            ->groupBy('journal_date')
            ->orderByDesc('journal_date')
            ->selectRaw('journal_date as date')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_income, 0)), 0) as total_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_expense, 0)), 0) as total_expense')
            ->get()
            ->all();
    }

    /**
     * @param  list<object>  $dailyRows  newest-first
     * @return array{total_monthly_income: string, total_monthly_expenses: string, monthly_net: string}
     */
    private function monthlyTotalsFromDailyRows(array $dailyRows): array
    {
        $income = 0.0;
        $expense = 0.0;

        foreach ($dailyRows as $row) {
            $income += (float) $row->total_income;
            $expense += (float) $row->total_expense;
        }

        $income = round($income, 2);
        $expense = round($expense, 2);

        return [
            'total_monthly_income' => FormatMoneyDecimal::format($income),
            'total_monthly_expenses' => FormatMoneyDecimal::format($expense),
            'monthly_net' => FormatMoneyDecimal::format(round($income - $expense, 2)),
        ];
    }

    /**
     * @param  list<object>  $dailyRows  newest-first activity days for the month
     * @return list<array<string, string>>
     */
    private function lastNDays(array $dailyRows, int $limit, bool $includeNet): array
    {
        $slice = array_slice($dailyRows, 0, $limit);
        $records = [];

        foreach ($slice as $row) {
            $income = round((float) $row->total_income, 2);
            $expense = round((float) $row->total_expense, 2);
            $date = Carbon::parse($row->date)->toDateString();

            if ($includeNet) {
                $records[] = [
                    'date' => $date,
                    'total_income' => FormatMoneyDecimal::format($income),
                    'total_expenses' => FormatMoneyDecimal::format($expense),
                    'net_result' => FormatMoneyDecimal::format(round($income - $expense, 2)),
                ];

                continue;
            }

            $records[] = [
                'date' => $date,
                'total_income' => FormatMoneyDecimal::format($income),
                'total_expense' => FormatMoneyDecimal::format($expense),
            ];
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     * @return array{
     *     total_projects: int,
     *     surplus_projects_count: int,
     *     deficit_projects_count: int,
     *     projects_with_administrative_debt_count: int
     * }
     */
    private function cashStationStatus(array $projects): array
    {
        $surplus = 0;
        $deficit = 0;
        $withDebt = 0;

        foreach ($projects as $project) {
            $status = (string) ($project['status'] ?? 'balanced');
            if ($status === 'surplus') {
                $surplus++;
            } elseif ($status === 'deficit') {
                $deficit++;
            }

            $debt = (float) ($project['remaining_administrative_debt'] ?? $project['administrative_debt'] ?? 0);
            if ($debt > 0) {
                $withDebt++;
            }
        }

        return [
            'total_projects' => count($projects),
            'surplus_projects_count' => $surplus,
            'deficit_projects_count' => $deficit,
            'projects_with_administrative_debt_count' => $withDebt,
        ];
    }
}
