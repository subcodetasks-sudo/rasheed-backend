<?php

namespace Modules\DailyJournal\Actions;

use Carbon\Carbon;

class ReadFundBalanceDeltaAction
{
    /**
     * Authoritative fund_balance movement per project over a period = fund_balance at $endDate minus
     * fund_balance the day before $startDate (0 if no prior entry) — a pure delta of two already-correct
     * fund_balance reads (see ReadFundBalanceAsOfAction), never an independent recomputation.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function execute(array $projectIds, string $startDate, string $endDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $readFundBalanceAsOf = new ReadFundBalanceAsOfAction;

        $before = $readFundBalanceAsOf->execute($projectIds, Carbon::parse($startDate)->subDay()->toDateString());
        $end = $readFundBalanceAsOf->execute($projectIds, $endDate);

        $deltas = [];
        foreach ($projectIds as $projectId) {
            $deltas[$projectId] = round(($end[$projectId] ?? 0.0) - ($before[$projectId] ?? 0.0), 2);
        }

        return $deltas;
    }
}
