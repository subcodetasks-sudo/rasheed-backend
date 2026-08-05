<?php

namespace Modules\Settings\Workflows;

use Modules\Settings\Actions\UpsertMonthlyEmployeeSettingsAction;

class UpdateMonthlyEmployeeSettingsWorkflow
{
    public function __construct(
        private readonly UpsertMonthlyEmployeeSettingsAction $upsertMonthlyEmployeeSettingsAction,
    ) {}

    /**
     * @param  array<string, float|int|string>  $categories
     */
    public function handle(int $month, int $year, array $categories): array
    {
        return $this->upsertMonthlyEmployeeSettingsAction->execute($month, $year, $categories);
    }
}
