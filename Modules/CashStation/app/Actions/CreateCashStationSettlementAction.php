<?php

namespace Modules\CashStation\Actions;

use Modules\CashStation\Models\CashStationSettlement;

class CreateCashStationSettlementAction
{
    public function execute(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        string $amount,
        ?string $createdBy = null,
    ): CashStationSettlement {
        return CashStationSettlement::query()->create([
            'year' => $year,
            'month' => $month,
            'from_project_id' => $fromProjectId,
            'to_project_id' => $toProjectId,
            'amount' => $amount,
            'created_by' => $createdBy,
        ]);
    }
}
