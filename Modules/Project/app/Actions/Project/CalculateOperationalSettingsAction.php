<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\DTOs\ProjectData;
use Modules\Project\Enums\OperationalDeductionType;

class CalculateOperationalSettingsAction
{
    /**
     * Normalize operational settings for persistence.
     * Changing these settings affects future financial operations only.
     *
     * @return array{operational_deduction_type: OperationalDeductionType, operational_fixed_amount: float|null, administrative_exempt: bool}
     */
    public function execute(ProjectData $data): array
    {
        $fixedAmount = $data->operationalDeductionType === OperationalDeductionType::Fixed
            ? $data->operationalFixedAmount
            : null;

        return [
            'operational_deduction_type' => $data->operationalDeductionType,
            'operational_fixed_amount' => $fixedAmount,
            'administrative_exempt' => $data->administrativeExempt,
        ];
    }
}
