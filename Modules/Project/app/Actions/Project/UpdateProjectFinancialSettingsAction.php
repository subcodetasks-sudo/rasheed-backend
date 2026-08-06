<?php

namespace Modules\Project\Actions\Project;

use Modules\Settings\Actions\UpdateSystemGeneralSettingsAction;

class UpdateProjectFinancialSettingsAction
{
    public function __construct(
        private readonly GetProjectFinancialSettingsAction $getProjectFinancialSettingsAction,
        private readonly UpdateSystemGeneralSettingsAction $updateSystemGeneralSettingsAction,
    ) {}

    /**
     * @param  array{admin_fee_percentage?: float|int|string}  $data
     * @return array{admin_fee_percentage: float, total_operational_deduction: float}
     */
    public function execute(array $data): array
    {
        if (array_key_exists('admin_fee_percentage', $data)) {
            $this->updateSystemGeneralSettingsAction->execute([
                'admin_fee_percentage' => $data['admin_fee_percentage'],
            ]);
        }

        return $this->getProjectFinancialSettingsAction->execute();
    }
}
