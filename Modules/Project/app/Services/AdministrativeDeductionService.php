<?php

namespace Modules\Project\Services;

use Carbon\CarbonInterface;
use Modules\Project\Actions\Project\ResolveEffectiveAdminFeePercentageAction;
use Modules\Project\Models\Project;

class AdministrativeDeductionService
{
    public function __construct(
        private readonly ResolveEffectiveAdminFeePercentageAction $resolveEffectiveAdminFeePercentageAction,
    ) {}

    public function calculate(Project $project, float $income, CarbonInterface|string|null $asOfDate = null): float
    {
        if ($project->administrative_exempt) {
            return 0.0;
        }

        $percentage = $this->resolveEffectiveAdminFeePercentageAction->execute($project, $asOfDate ?? now());

        return round($income * ($percentage / 100), 2);
    }
}
