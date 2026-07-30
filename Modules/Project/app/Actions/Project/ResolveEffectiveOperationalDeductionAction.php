<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\OperationalDeductionRate;

class ResolveEffectiveOperationalDeductionAction
{
    public const DEFAULT_TOTAL = 1081.0;

    public function execute(CarbonInterface|string $journalDate): float
    {
        $date = Carbon::parse($journalDate)->toDateString();

        $rate = OperationalDeductionRate::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if ($rate === null) {
            return self::DEFAULT_TOTAL;
        }

        return round((float) $rate->amount, 2);
    }
}
