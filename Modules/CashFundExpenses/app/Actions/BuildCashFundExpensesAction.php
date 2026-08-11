<?php

namespace Modules\CashFundExpenses\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BuildCashFundExpensesAction
{
    public function execute(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $daysInMonth = $startOfMonth->daysInMonth;
        $days = range(1, $daysInMonth);

        $rows = DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->where('daily_journal_entries.journal_date', '>=', $startOfMonth->toDateString())
            ->where('daily_journal_entries.journal_date', '<=', $endOfMonth->toDateString())
            ->groupBy(
                'daily_journal_entries.project_id',
                'projects.name',
                'daily_journal_entries.journal_date',
            )
            ->orderBy('daily_journal_entries.project_id')
            ->orderBy('daily_journal_entries.journal_date')
            ->selectRaw('daily_journal_entries.project_id as project_id')
            ->selectRaw('projects.name as project_name')
            ->selectRaw('daily_journal_entries.journal_date as journal_date')
            ->selectRaw(
                'COALESCE(SUM('
                .'COALESCE(daily_journal_entries.daily_expense, 0)'
                .' + COALESCE(daily_journal_entries.administrative_expense, 0)'
                .'), 0) as day_total'
            )
            ->get();

        /** @var array<int, array{project_id: int, project_name: string, daily_amounts: array<int, float>}> $byProject */
        $byProject = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $rawDate = (string) $row->journal_date;
            $dateString = strlen($rawDate) >= 10 ? substr($rawDate, 0, 10) : $rawDate;
            $day = (int) substr($dateString, 8, 2);
            $amount = round((float) $row->day_total, 2);

            if ($day < 1 || $day > $daysInMonth) {
                continue;
            }

            if (! isset($byProject[$projectId])) {
                $byProject[$projectId] = [
                    'project_id' => $projectId,
                    'project_name' => (string) $row->project_name,
                    'daily_amounts' => [],
                ];
            }

            $byProject[$projectId]['daily_amounts'][$day] =
                ($byProject[$projectId]['daily_amounts'][$day] ?? 0.0) + $amount;
        }

        $projects = [];
        foreach ($byProject as $project) {
            $dailyExpenses = [];
            $monthlyTotal = 0.0;

            foreach ($days as $day) {
                $amount = round((float) ($project['daily_amounts'][$day] ?? 0.0), 2);
                if ($amount > 0) {
                    $dailyExpenses[(string) $day] = FormatMoneyDecimal::formatRounded($amount);
                    $monthlyTotal += $amount;
                } else {
                    $dailyExpenses[(string) $day] = null;
                }
            }

            $monthlyTotal = round($monthlyTotal, 2);
            if ($monthlyTotal <= 0) {
                continue;
            }

            $projects[] = [
                'project_id' => $project['project_id'],
                'project_name' => $project['project_name'],
                'daily_expenses' => (object) $dailyExpenses,
                'monthly_total' => FormatMoneyDecimal::formatRounded($monthlyTotal),
            ];
        }

        return [
            'month' => $month,
            'year' => $year,
            'days' => $days,
            'projects' => $projects,
        ];
    }
}
