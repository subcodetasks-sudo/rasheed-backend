<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\AdministrativeFeeRate;
use Modules\Project\Models\Project;

class SeedAdministrativeFeeRateAction
{
    public function execute(Project $project, float $percentage, CarbonInterface|string|null $effectiveFrom = null): void
    {
        AdministrativeFeeRate::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'effective_from' => Carbon::parse($effectiveFrom ?? now())->toDateString(),
            ],
            ['percentage' => round($percentage, 2)]
        );
    }
}
