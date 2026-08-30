<?php

namespace Modules\Project\Actions\Project;

use Carbon\Carbon;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;

class ResolveTotalOperationalDeductionAction
{
    /**
     * Total daily operational deduction for display: current month's employee category sum.
     * This already matches the total_daily_operational_deduction rule (fixed-type projects are a
     * carve-out of this total, not an addition on top of it) — no fixed-sum addition here.
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
