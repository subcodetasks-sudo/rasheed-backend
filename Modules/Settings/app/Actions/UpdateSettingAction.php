<?php

namespace Modules\Settings\Actions;

use Modules\Settings\Services\SettingService;

class UpdateSettingAction
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function execute(string $key, mixed $value, string $type = 'string', bool $isPublic = true): mixed
    {
        $this->settingService->update($key, $value, $type, $isPublic);

        return $this->settingService->get($key);
    }
}
