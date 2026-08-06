<?php

namespace Modules\Settings\Actions;

use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;
use Modules\Project\Actions\Project\ScheduleAdminFeePercentageChangeAction;
use Modules\Settings\Services\SettingService;

class UpdateSettingAction
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly ScheduleAdminFeePercentageChangeAction $scheduleAdminFeePercentageChangeAction,
    ) {}

    public function execute(string $key, mixed $value, string $type = 'string', bool $isPublic = true): mixed
    {
        $resolvedKey = $this->settingService->resolveKey($key);

        $this->settingService->update($key, $value, $type, $isPublic);

        if ($resolvedKey === ResolveAdminFeePercentageAction::SETTING_KEY) {
            $this->scheduleAdminFeePercentageChangeAction->execute((float) $value);
        }

        return $this->settingService->get($key);
    }
}
