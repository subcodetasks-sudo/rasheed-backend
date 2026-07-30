<?php

namespace Modules\Settings\Actions;

use Modules\Project\Actions\Project\ResolveTotalOperationalDeductionAction;
use Modules\Project\Actions\Project\ScheduleOperationalDeductionChangeAction;
use Modules\Settings\Services\SettingService;

class UpdateSettingAction
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly ScheduleOperationalDeductionChangeAction $scheduleOperationalDeductionChangeAction,
    ) {}

    public function execute(string $key, mixed $value, string $type = 'string', bool $isPublic = true): mixed
    {
        $this->settingService->update($key, $value, $type, $isPublic);

        if ($key === ResolveTotalOperationalDeductionAction::SETTING_KEY) {
            $this->scheduleOperationalDeductionChangeAction->execute((float) $value);
        }

        return $this->settingService->get($key);
    }
}
