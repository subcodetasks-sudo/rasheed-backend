<?php

namespace App\Support;

final class ArabicLocale
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public static function trans(string $key, array $replace = []): string
    {
        return trans($key, $replace, 'ar');
    }

    public static function label(string $token): string
    {
        $key = 'messages.audit_label_'.$token;
        $translated = trans($key, [], 'ar');

        return $translated === $key ? $token : $translated;
    }

    public static function resource(string $slug): string
    {
        $key = 'messages.audit_resource_'.$slug;
        $translated = trans($key, [], 'ar');

        return $translated === $key ? $slug : $translated;
    }
}
