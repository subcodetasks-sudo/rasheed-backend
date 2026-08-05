<?php

namespace Modules\Settings\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Settings\app\Models\Setting;

class SettingService
{
    public const APP_NAME_KEY = 'app_name';

    public const ORGANIZATION_NAME_ALIAS = 'organization_name';

    protected string $cacheKey = 'system_settings';

    public function resolveKey(string $key): string
    {
        return $key === self::ORGANIZATION_NAME_ALIAS
            ? self::APP_NAME_KEY
            : $key;
    }

    public function get(string $key, $default = null)
    {
        $settings = Cache::rememberForever($this->cacheKey, function () {
            return Setting::all()->pluck('value', 'key');
        });

        $resolved = $this->resolveKey($key);

        return $settings[$resolved] ?? $default;
    }

    public function update(string $key, $value, string $type = 'string', bool $isPublic = true): void
    {
        $resolved = $this->resolveKey($key);

        Setting::updateOrCreate(
            ['key' => $resolved],
            [
                'value' => $value,
                'type' => $type,
                'is_public' => $isPublic,
            ]
        );

        Cache::forget($this->cacheKey);
        Cache::forget($this->cacheKey.'_models');
    }

    public function all(): Collection
    {
        $settings = Cache::rememberForever($this->cacheKey.'_models', function () {
            return Setting::where('is_public', true)->get();
        });

        $appName = $settings->firstWhere('key', self::APP_NAME_KEY);
        if ($appName === null) {
            return $settings;
        }

        $hasAlias = $settings->contains(fn (Setting $setting) => $setting->key === self::ORGANIZATION_NAME_ALIAS);
        if ($hasAlias) {
            return $settings;
        }

        $alias = $appName->replicate();
        $alias->id = null;
        $alias->key = self::ORGANIZATION_NAME_ALIAS;
        $alias->exists = false;

        return $settings->concat([$alias])->values();
    }
}
