<?php

namespace Modules\Settings\Services;
use Illuminate\Support\Facades\Cache;
use Modules\Settings\app\Models\Setting;


class SettingService {

    protected string $cacheKey = 'system_settings';

    public function get(string $key, $default = null) {
        $settings = Cache::rememberForever($this->cacheKey, function () {
            return Setting::all()->pluck('value', 'key');
        });
        return $settings[$key] ?? $default;
    }

    public function update(string $key, $value, string $type = 'string', bool $isPublic = true): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type'  => $type,
                'is_public' => $isPublic
            ]
        );

        Cache::forget($this->cacheKey);
        Cache::forget($this->cacheKey . '_models');
    }

    public function all() {
        return Cache::rememberForever($this->cacheKey . '_models', function () {
            return Setting::where('is_public', true)->get();
        });
    }
}