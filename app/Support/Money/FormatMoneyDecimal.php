<?php

namespace App\Support\Money;

final class FormatMoneyDecimal
{
    public static function format(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
