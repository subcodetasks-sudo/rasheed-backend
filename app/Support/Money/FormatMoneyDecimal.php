<?php

namespace App\Support\Money;

final class FormatMoneyDecimal
{
    public static function format(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    public static function formatRounded(mixed $value): string
    {
        return number_format((float) $value, 0, '.', '');
    }
}
