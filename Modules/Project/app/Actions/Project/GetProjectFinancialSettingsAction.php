<?php

namespace Modules\Project\Actions\Project;

class GetProjectFinancialSettingsAction
{
    public function __construct(
        private readonly ResolveAdminFeePercentageAction $resolveAdminFeePercentageAction,
        private readonly ResolveTotalOperationalDeductionAction $resolveTotalOperationalDeductionAction,
    ) {}

    /**
     * @return array{admin_fee_percentage: float, total_operational_deduction: float}
     */
    public function execute(): array
    {
        return [
            'admin_fee_percentage' => $this->resolveAdminFeePercentageAction->execute(),
            'total_operational_deduction' => $this->resolveTotalOperationalDeductionAction->execute(),
        ];
    }
}
