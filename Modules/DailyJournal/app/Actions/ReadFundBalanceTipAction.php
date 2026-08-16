<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Facades\DB;

class ReadFundBalanceTipAction
{
    /**
     * Read the tip (latest row as-of `$asOfDate`) fund_balance per project — the
     * authoritative, cumulative Daily Journal balance used to ground any downstream
     * "available surplus" calculation. Shared to avoid drift from duplicated SQL.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function execute(array $projectIds, string $asOfDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $balances = [];

        foreach ($projectIds as $projectId) {
            $row = DB::table('daily_journal_entries')
                ->where('project_id', $projectId)
                ->whereDate('journal_date', '<=', $asOfDate)
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->first(['fund_balance']);

            if ($row !== null) {
                $balances[(int) $projectId] = (float) ($row->fund_balance ?? 0);
            }
        }

        return $balances;
    }
}
