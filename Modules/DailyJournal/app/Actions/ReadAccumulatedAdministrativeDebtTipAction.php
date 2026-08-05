<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Facades\DB;

class ReadAccumulatedAdministrativeDebtTipAction
{
    /**
     * Read the month-tip (latest row as-of `$asOfDate`) accumulated admin debt per project.
     *
     * This is shared between CashStation and Administrative Debt Settlement builders
     * to avoid drift from duplicated SQL.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function execute(array $projectIds, string $asOfDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $debts = [];

        foreach ($projectIds as $projectId) {
            $row = DB::table('daily_journal_entries')
                ->where('project_id', $projectId)
                ->whereDate('journal_date', '<=', $asOfDate)
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->first(['accumulated_administrative_debt']);

            if ($row !== null) {
                $debts[(int) $projectId] = (float) ($row->accumulated_administrative_debt ?? 0);
            }
        }

        return $debts;
    }
}
