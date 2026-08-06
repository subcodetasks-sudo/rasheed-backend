<?php

namespace Modules\Settings\Support;

final class SupportedCurrencies
{
    public const ALLOWED = ['SAR', 'USD', 'AED'];

    public static function contains(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ALLOWED, true);
    }
}
