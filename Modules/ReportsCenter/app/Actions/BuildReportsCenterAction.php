<?php

namespace Modules\ReportsCenter\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\DailyJournal\Actions\ReadAccumulatedAdministrativeDebtTipAction;
use Modules\DailyJournal\Actions\ReadFundBalanceDeltaAction;
use Modules\Project\Models\Project;

class BuildReportsCenterAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly ReadAccumulatedAdministrativeDebtTipAction $readAccumulatedAdministrativeDebtTipAction,
    ) {}

    /**
     * @param  array{period_type: string, start_date: string, end_date: string, month?: int, year?: int}  $period
     */
    public function execute(array $period): array
    {
        $startDate = $period['start_date'];
        $endDate = $period['end_date'];
        // Upper bound uses end-of-day so DATE/DATETIME journal rows on the end date are included
        // (SQLite string compare of 'Y-m-d H:i:s' <= 'Y-m-d' is false).
        $endBound = Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s');

        $projects = Project::query()
            ->active()
            ->orderBy('id')
            ->get(['id', 'name', 'fund_type']);

        $projectIds = $projects->pluck('id')->map(fn ($id) => (int) $id)->all();

        $aggregates = $this->buildCashStationAction->monthlyAggregatesByProject(
            $projectIds,
            $startDate,
            $endBound,
        );

        // net is the field claiming "this project's fund result for the period" — the single source of
        // truth, read from Daily Journal's own fund_balance rather than independently recomputed from
        // the raw SUM()'d columns above (those still drive income/expense/administrative/operational).
        $fundBalanceDeltas = (new ReadFundBalanceDeltaAction)->execute($projectIds, $startDate, $endDate);

        $debts = $this->readAccumulatedAdministrativeDebtTipAction->execute($projectIds, $endDate);

        $projectRows = [];
        $comparison = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        $totalAdministrative = 0.0;
        $totalOperational = 0.0;
        $totalNet = 0.0;

        foreach ($projects as $project) {
            $projectId = (int) $project->id;
            $aggregate = $aggregates[$projectId] ?? null;

            $income = round((float) ($aggregate->monthly_revenue ?? 0), 2);
            $expense = round((float) ($aggregate->monthly_expenses ?? 0), 2);
            $administrative = round((float) ($aggregate->administrative_percentage ?? 0), 2);
            $operational = round((float) ($aggregate->operational_deduction ?? 0), 2);
            $net = round($fundBalanceDeltas[$projectId] ?? 0.0, 2);
            $debt = round((float) ($debts[$projectId] ?? 0), 2);

            $fundType = $project->fund_type?->value ?? (string) $project->fund_type;

            $projectRows[] = [
                'project_id' => $projectId,
                'project_name' => (string) $project->name,
                'fund_type' => $fundType,
                'income' => FormatMoneyDecimal::formatRounded($income),
                'expense' => FormatMoneyDecimal::formatRounded($expense),
                'administrative' => FormatMoneyDecimal::formatRounded($administrative),
                'operational' => FormatMoneyDecimal::formatRounded($operational),
                'net' => FormatMoneyDecimal::formatRounded($net),
                'debt' => FormatMoneyDecimal::formatRounded($debt),
            ];

            $comparison[] = [
                'project_id' => $projectId,
                'project_name' => (string) $project->name,
                'income' => FormatMoneyDecimal::formatRounded($income),
                'expense' => FormatMoneyDecimal::formatRounded($expense),
                'net' => FormatMoneyDecimal::formatRounded($net),
            ];

            $totalIncome += $income;
            $totalExpense += $expense;
            $totalAdministrative += $administrative;
            $totalOperational += $operational;
            $totalNet += $net;
        }

        $totalIncome = round($totalIncome, 2);
        $totalExpense = round($totalExpense, 2);
        $totalAdministrative = round($totalAdministrative, 2);
        $totalOperational = round($totalOperational, 2);
        $totalNet = round($totalNet, 2);

        $periodPayload = [
            'period_type' => $period['period_type'],
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        if (($period['period_type'] ?? null) === 'month') {
            $periodPayload['month'] = (int) ($period['month'] ?? 0);
            $periodPayload['year'] = (int) ($period['year'] ?? 0);
        }

        return [
            'period' => $periodPayload,
            'summary' => [
                'total_income' => FormatMoneyDecimal::formatRounded($totalIncome),
                'total_expense' => FormatMoneyDecimal::formatRounded($totalExpense),
                'administrative_percentage' => FormatMoneyDecimal::formatRounded($totalAdministrative),
                'operational_deduction' => FormatMoneyDecimal::formatRounded($totalOperational),
                'net_cash_funds' => FormatMoneyDecimal::formatRounded($totalNet),
            ],
            'charts' => [
                'income_expense_movement' => $this->incomeExpenseMovement($startDate, $endDate, $endBound, $projectIds),
                'expense_distribution' => [
                    'direct_expenses' => FormatMoneyDecimal::formatRounded($totalExpense),
                    'administrative_percentage' => FormatMoneyDecimal::formatRounded($totalAdministrative),
                    'operational_deduction' => FormatMoneyDecimal::formatRounded($totalOperational),
                ],
                'project_comparison' => $comparison,
            ],
            'projects' => $projectRows,
            'totals' => [
                'total_income' => FormatMoneyDecimal::formatRounded($totalIncome),
                'total_expense' => FormatMoneyDecimal::formatRounded($totalExpense),
                'total_administrative' => FormatMoneyDecimal::formatRounded($totalAdministrative),
                'total_operational' => FormatMoneyDecimal::formatRounded($totalOperational),
                'total_net' => FormatMoneyDecimal::formatRounded($totalNet),
            ],
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return list<array{date: string, daily_income: string, daily_expense: string, daily_net_movement: string}>
     */
    private function incomeExpenseMovement(string $startDate, string $endDate, string $endBound, array $projectIds): array
    {
        $keyed = [];

        if ($projectIds !== []) {
            $rows = DB::table('daily_journal_entries')
                ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
                ->whereIn('daily_journal_entries.project_id', $projectIds)
                ->where('daily_journal_entries.journal_date', '>=', $startDate)
                ->where('daily_journal_entries.journal_date', '<=', $endBound)
                ->groupBy('daily_journal_entries.journal_date')
                ->selectRaw('daily_journal_entries.journal_date as date')
                ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_income, 0)), 0) as daily_income')
                ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_expense, 0)), 0) as daily_expense')
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN projects.administrative_exempt = 0 THEN '
                    .'COALESCE(daily_journal_entries.administrative_fee, 0)'
                    .' - COALESCE(daily_journal_entries.administrative_debt, 0)'
                    .' + COALESCE(daily_journal_entries.contribution, 0)'
                    .' ELSE 0 END), 0) as administrative_percentage'
                )
                ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.operational_deduction, 0)), 0) as operational_deduction')
                ->get();

            foreach ($rows as $row) {
                $dateKey = Carbon::parse($row->date)->toDateString();
                $keyed[$dateKey] = $row;
            }
        }

        $records = [];
        $cursor = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        while ($cursor->lte($end)) {
            $dateString = $cursor->toDateString();
            $row = $keyed[$dateString] ?? null;

            $income = round((float) ($row->daily_income ?? 0), 2);
            $expense = round((float) ($row->daily_expense ?? 0), 2);
            $administrative = round((float) ($row->administrative_percentage ?? 0), 2);
            $operational = round((float) ($row->operational_deduction ?? 0), 2);
            $net = round($income - $expense - $administrative - $operational, 2);

            $records[] = [
                'date' => $dateString,
                'daily_income' => FormatMoneyDecimal::formatRounded($income),
                'daily_expense' => FormatMoneyDecimal::formatRounded($expense),
                'daily_net_movement' => FormatMoneyDecimal::formatRounded($net),
            ];

            $cursor->addDay();
        }

        return $records;
    }
}
