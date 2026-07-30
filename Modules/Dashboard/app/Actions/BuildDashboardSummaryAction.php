<?php

namespace Modules\Dashboard\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class BuildDashboardSummaryAction
{
    public function execute(CarbonInterface $journalDate): array
    {
        $dateString = $journalDate->toDateString();

        $allProjects = DB::table('daily_journal_entries')
            ->whereDate('journal_date', $dateString)
            ->selectRaw('COALESCE(SUM(COALESCE(daily_income, 0)), 0) as total_daily_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_expense, 0)), 0) as total_daily_expenses')
            ->selectRaw('COALESCE(SUM(COALESCE(operational_deduction, 0)), 0) as total_operational_deduction')
            ->first();

        $administrativePercentage = DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->where('projects.administrative_exempt', false)
            ->whereDate('daily_journal_entries.journal_date', $dateString)
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.administrative_fee, 0)), 0) as total_administrative_percentage')
            ->value('total_administrative_percentage');

        return [
            'total_daily_income' => $this->decimal($allProjects->total_daily_income ?? 0),
            'total_daily_expenses' => $this->decimal($allProjects->total_daily_expenses ?? 0),
            'total_administrative_percentage' => $this->decimal($administrativePercentage ?? 0),
            'total_operational_deduction' => $this->decimal($allProjects->total_operational_deduction ?? 0),
        ];
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
