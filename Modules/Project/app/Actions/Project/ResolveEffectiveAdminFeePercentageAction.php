<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\AdministrativeFeeRate;
use Modules\Project\Models\Project;

class ResolveEffectiveAdminFeePercentageAction
{
    public const DEFAULT_PERCENTAGE = 12.0;

    public function execute(Project $project, CarbonInterface|string $journalDate): float
    {
        $date = Carbon::parse($journalDate)->toDateString();

        $rate = AdministrativeFeeRate::query()
            ->where('project_id', $project->id)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if ($rate !== null) {
            return round((float) $rate->percentage, 2);
        }

        if ($project->administrative_fee_percentage !== null) {
            return round((float) $project->administrative_fee_percentage, 2);
        }

        return self::DEFAULT_PERCENTAGE;
    }
}
