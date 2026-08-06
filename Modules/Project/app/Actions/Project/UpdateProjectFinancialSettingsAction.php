<?php

namespace Modules\Project\Actions\Project;

use Modules\Settings\Services\SettingService;

class UpdateProjectFinancialSettingsAction
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly GetProjectFinancialSettingsAction $getProjectFinancialSettingsAction,
        private readonly ScheduleAdminFeePercentageChangeAction $scheduleAdminFeePercentageChangeAction,
    ) {}

    /**
     * @param  array{admin_fee_percentage?: float|int|string, total_operational_deduction?: float|int|string}  $data
     * @return array{admin_fee_percentage: float, total_operational_deduction: float}
     */
    public function execute(array $data): array
    {
        if (array_key_exists('admin_fee_percentage', $data)) {
            $this->settingService->update(
                'admin_fee_percentage',
                $data['admin_fee_percentage'],
                'decimal',
                true
            );

            $this->scheduleAdminFeePercentageChangeAction->execute(
                (float) $data['admin_fee_percentage']
            );
        }

        if (array_key_exists('total_operational_deduction', $data)) {
            $this->settingService->update(
                'total_operational_deduction',
                $data['total_operational_deduction'],
                'decimal',
                true
            );
        }

        return $this->getProjectFinancialSettingsAction->execute();
    }
}
