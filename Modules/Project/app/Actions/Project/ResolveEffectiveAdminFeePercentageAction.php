<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\AdministrativeFeeRate;

class ResolveEffectiveAdminFeePercentageAction
{
    public const DEFAULT_PERCENTAGE = 12.0;

    public function execute(CarbonInterface|string $journalDate): float
    {
        $date = Carbon::parse($journalDate)->toDateString();

        $rate = AdministrativeFeeRate::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if ($rate === null) {
            return self::DEFAULT_PERCENTAGE;
        }

        return round((float) $rate->percentage, 2);
    }
}
