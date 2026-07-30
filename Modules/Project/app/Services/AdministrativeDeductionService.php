<?php

namespace Modules\Project\Services;

use Modules\Project\Models\Project;

class AdministrativeDeductionService
{
    public function calculate(Project $project, float $income): float
    {
        if ($project->administrative_exempt) {
            return 0.0;
        }

        $percentage = (float) ($project->administrative_fee_percentage ?? 0);

        return round($income * ($percentage / 100), 2);
    }
}
