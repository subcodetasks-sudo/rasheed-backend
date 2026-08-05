<?php

namespace Modules\Settings\Actions;

use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;
use Modules\Project\Actions\Project\ResolveTotalOperationalDeductionAction;
use Modules\Project\Actions\Project\ScheduleAdminFeePercentageChangeAction;
use Modules\Project\Actions\Project\ScheduleOperationalDeductionChangeAction;
use Modules\Settings\Services\SettingService;

class UpdateSettingAction
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly ScheduleOperationalDeductionChangeAction $scheduleOperationalDeductionChangeAction,
        private readonly ScheduleAdminFeePercentageChangeAction $scheduleAdminFeePercentageChangeAction,
    ) {}

    public function execute(string $key, mixed $value, string $type = 'string', bool $isPublic = true): mixed
    {
        $resolvedKey = $this->settingService->resolveKey($key);

        $this->settingService->update($key, $value, $type, $isPublic);

        if ($resolvedKey === ResolveTotalOperationalDeductionAction::SETTING_KEY) {
            $this->scheduleOperationalDeductionChangeAction->execute((float) $value);
        }

        if ($resolvedKey === ResolveAdminFeePercentageAction::SETTING_KEY) {
            $this->scheduleAdminFeePercentageChangeAction->execute((float) $value);
        }

        return $this->settingService->get($key);
    }
}
