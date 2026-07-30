<?php

namespace Modules\Project\Actions\Project;

use Modules\Settings\Services\SettingService;

class ResolveTotalOperationalDeductionAction
{
    public const DEFAULT_TOTAL = 1081.0;

    public const SETTING_KEY = 'total_operational_deduction';

    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function execute(): float
    {
        $value = $this->settingService->get(self::SETTING_KEY, self::DEFAULT_TOTAL);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_TOTAL;
        }

        return round((float) $value, 2);
    }
}
