<?php

namespace Modules\Settings\Workflows;

use Modules\Settings\Actions\GetSystemGeneralSettingsAction;

class ShowSystemGeneralSettingsWorkflow
{
    public function __construct(
        private readonly GetSystemGeneralSettingsAction $getSystemGeneralSettingsAction,
    ) {}

    /**
     * @return array{organization_name: string, currency: string, admin_fee_percentage: float}
     */
    public function handle(): array
    {
        return $this->getSystemGeneralSettingsAction->execute();
    }
}
