<?php

namespace Modules\Project\Actions\Project;

use Carbon\Carbon;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;

class ResolveTotalOperationalDeductionAction
{
    /**
     * Relative operational pool for display: current month's employee category sum.
     * Journal math uses ResolveEffectiveOperationalDeductionAction (rates table).
     */
    public function execute(): float
    {
        $today = Carbon::now()->startOfDay();

        $row = MonthlyEmployeeSetting::query()
            ->where('year', (int) $today->year)
            ->where('month', (int) $today->month)
            ->first();

        if ($row === null) {
            return 0.0;
        }

        return $row->relativeDeduction();
    }
}
