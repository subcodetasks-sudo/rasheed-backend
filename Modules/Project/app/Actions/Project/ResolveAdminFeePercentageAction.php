<?php

namespace Modules\Project\Actions\Project;

use Modules\Settings\Services\SettingService;

class ResolveAdminFeePercentageAction
{
    public const DEFAULT_PERCENTAGE = 12.0;

    public const SETTING_KEY = 'admin_fee_percentage';

    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function execute(): float
    {
        $value = $this->settingService->get(self::SETTING_KEY, self::DEFAULT_PERCENTAGE);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_PERCENTAGE;
        }

        return round((float) $value, 2);
    }
}
