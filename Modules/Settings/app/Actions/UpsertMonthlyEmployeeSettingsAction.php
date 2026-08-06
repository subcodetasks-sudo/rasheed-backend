<?php

namespace Modules\Settings\Actions;

use Carbon\Carbon;
use Modules\Project\Actions\Project\ScheduleOperationalDeductionChangeAction;
use Modules\Project\Actions\Project\UpsertOperationalDeductionRateAction;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;
use Modules\Settings\Support\MonthlyEmployeeCategories;

class UpsertMonthlyEmployeeSettingsAction
{
    public function __construct(
        private readonly ScheduleOperationalDeductionChangeAction $scheduleOperationalDeductionChangeAction,
        private readonly UpsertOperationalDeductionRateAction $upsertOperationalDeductionRateAction,
        private readonly BuildMonthlyEmployeeSettingsViewAction $buildMonthlyEmployeeSettingsViewAction,
    ) {}

    /**
     * @param  array<string, float|int|string>  $categories
     */
    public function execute(int $month, int $year, array $categories): array
    {
        $amounts = MonthlyEmployeeCategories::normalize($categories);

        $row = MonthlyEmployeeSetting::query()->updateOrCreate(
            ['year' => $year, 'month' => $month],
            $amounts,
        );

        $this->syncOperationalPool($month, $year, $row->relativeDeduction());

        return $this->buildMonthlyEmployeeSettingsViewAction->execute($month, $year, $row);
    }

    private function syncOperationalPool(int $month, int $year, float $relative): void
    {
        $now = Carbon::now()->startOfDay();
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($now->gte($monthStart) && $now->lte($monthEnd)) {
            $this->scheduleOperationalDeductionChangeAction->execute($relative, $now);

            return;
        }

        $this->upsertOperationalDeductionRateAction->execute($monthStart->toDateString(), $relative);
    }
}
