<?php

namespace Modules\Settings\Workflows;

use Modules\Settings\Actions\BuildMonthlyEmployeeSettingsViewAction;

class ShowMonthlyEmployeeSettingsWorkflow
{
    public function __construct(
        private readonly BuildMonthlyEmployeeSettingsViewAction $buildMonthlyEmployeeSettingsViewAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildMonthlyEmployeeSettingsViewAction->execute($month, $year);
    }
}
