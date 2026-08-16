<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Facades\DB;

class ReadFundBalanceAsOfAction
{
    /**
     * Read the authoritative fund_balance (latest row as-of `$asOfDate`) per project.
     *
     * This is the single source of truth for "is this project's fund in surplus or deficit" — every
     * consumer (CashStation, MonthlySummary, ReportsCenter, ...) must read it here instead of
     * independently recomputing a balance from raw daily_journal_entries columns.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float> project_id => fund_balance (project absent from the array if it has no
     *                            entry on or before $asOfDate)
     */
    public function execute(array $projectIds, string $asOfDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $latestDates = DB::table('daily_journal_entries')
            ->selectRaw('project_id, MAX(journal_date) as journal_date')
            ->whereIn('project_id', $projectIds)
            ->whereDate('journal_date', '<=', $asOfDate)
            ->groupBy('project_id');

        $rows = DB::table('daily_journal_entries')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('daily_journal_entries.project_id', '=', 'latest.project_id')
                    ->on('daily_journal_entries.journal_date', '=', 'latest.journal_date');
            })
            ->select('daily_journal_entries.project_id', 'daily_journal_entries.fund_balance')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row->project_id] = (float) $row->fund_balance;
        }

        return $balances;
    }
}
