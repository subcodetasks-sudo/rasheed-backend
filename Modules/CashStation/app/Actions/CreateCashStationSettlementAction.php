<?php

namespace Modules\CashStation\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\MonthlySummary\Enums\ContributionType;

class CreateCashStationSettlementAction
{
    public function execute(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        string $amount,
        ?string $createdBy = null,
        ?ContributionType $contributionType = null,
    ): CashStationSettlement {
        return CashStationSettlement::query()->create([
            'year' => $year,
            'month' => $month,
            'from_project_id' => $fromProjectId,
            'to_project_id' => $toProjectId,
            'amount' => $amount,
            'contribution_type' => $contributionType,
            'created_by' => $createdBy,
        ]);
    }
}
