<?php

namespace Modules\DailyJournal\Support;

final class FundBalanceStatus
{
    /**
     * Same whole-unit precision as FormatMoneyDecimal::formatRounded() / Cash Station status.
     */
    public static function from(float $fundBalance): string
    {
        $displayed = round($fundBalance);

        if ($displayed > 0) {
            return 'surplus';
        }

        if ($displayed < 0) {
            return 'deficit';
        }

        return 'balanced';
    }
}
