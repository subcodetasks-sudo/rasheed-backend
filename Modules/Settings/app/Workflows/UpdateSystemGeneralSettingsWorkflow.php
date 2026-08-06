<?php

namespace Modules\Settings\Workflows;

use Modules\Settings\Actions\UpdateSystemGeneralSettingsAction;

class UpdateSystemGeneralSettingsWorkflow
{
    public function __construct(
        private readonly UpdateSystemGeneralSettingsAction $updateSystemGeneralSettingsAction,
    ) {}

    /**
     * @param  array{organization_name?: string, currency?: string, admin_fee_percentage?: float|int|string}  $data
     * @return array{organization_name: string, currency: string, admin_fee_percentage: float}
     */
    public function handle(array $data): array
    {
        return $this->updateSystemGeneralSettingsAction->execute($data);
    }
}
