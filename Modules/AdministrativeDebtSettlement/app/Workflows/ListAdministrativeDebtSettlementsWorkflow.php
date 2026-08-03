<?php

namespace Modules\AdministrativeDebtSettlement\Workflows;

use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;

class ListAdministrativeDebtSettlementsWorkflow
{
    public function __construct(
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildAdministrativeDebtSettlementAction->execute($month, $year);
    }
}
