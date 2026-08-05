<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;

class SumFixedOperationalDeductionsAction
{
    public function execute(): float
    {
        $fixedSum = (float) Project::query()
            ->active()
            ->where('operational_deduction_type', OperationalDeductionType::Fixed)
            ->selectRaw('COALESCE(SUM(COALESCE(operational_fixed_amount, 0)), 0) as total')
            ->value('total');

        return round($fixedSum, 2);
    }
}
