<?php

namespace Modules\Settings\Support;

final class MonthlyEmployeeCategories
{
    public const KEYS = [
        'fixed_workers',
        'media_staff',
        'administrative_staff',
        'variable_workers',
        'speakers',
        'cooks',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, float>
     */
    public static function normalize(array $input): array
    {
        $amounts = [];
        foreach (self::KEYS as $key) {
            $amounts[$key] = round((float) ($input[$key] ?? 0), 2);
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function sum(array $data): float
    {
        $total = 0.0;

        foreach (self::KEYS as $key) {
            $total += (float) ($data[$key] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @return array<string, float>
     */
    public static function zeros(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = 0.0;
        }

        return $out;
    }
}
