<?php

namespace Modules\Settings\Actions;

use Carbon\Carbon;
use Modules\Project\Actions\Project\ResolveTotalOperationalDeductionAction;
use Modules\Project\Actions\Project\ScheduleOperationalDeductionChangeAction;
use Modules\Project\Models\OperationalDeductionRate;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;
use Modules\Settings\Services\SettingService;
use Modules\Settings\Support\MonthlyEmployeeCategories;

class UpsertMonthlyEmployeeSettingsAction
{
    public function __construct(
        private readonly ScheduleOperationalDeductionChangeAction $scheduleOperationalDeductionChangeAction,
        private readonly SettingService $settingService,
        private readonly BuildMonthlyEmployeeSettingsViewAction $buildMonthlyEmployeeSettingsViewAction,
    ) {}

    /**
     * @param  array<string, float|int|string>  $categories
     */
    public function execute(int $month, int $year, array $categories): array
    {
        $amounts = [];
        foreach (MonthlyEmployeeCategories::KEYS as $key) {
            $amounts[$key] = round((float) ($categories[$key] ?? 0), 2);
        }

        $relative = MonthlyEmployeeCategories::sum($amounts);

        $row = MonthlyEmployeeSetting::query()->updateOrCreate(
            ['year' => $year, 'month' => $month],
            $amounts,
        );

        $this->syncOperationalPool($month, $year, $relative);

        return $this->buildMonthlyEmployeeSettingsViewAction->execute($month, $year, $row);
    }

    private function syncOperationalPool(int $month, int $year, float $relative): void
    {
        $now = Carbon::now()->startOfDay();
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($now->gte($monthStart) && $now->lte($monthEnd)) {
            $this->scheduleOperationalDeductionChangeAction->execute($relative, $now);
            $this->settingService->update(
                ResolveTotalOperationalDeductionAction::SETTING_KEY,
                $relative,
                'decimal',
                true,
            );

            return;
        }

        $this->upsertMonthStartRate($monthStart->toDateString(), $relative);
    }

    private function upsertMonthStartRate(string $date, float $amount): void
    {
        $existing = OperationalDeductionRate::query()
            ->whereDate('effective_from', $date)
            ->first();

        if ($existing !== null) {
            $existing->update(['amount' => round($amount, 2)]);

            return;
        }

        OperationalDeductionRate::query()->create([
            'effective_from' => $date,
            'amount' => round($amount, 2),
        ]);
    }
}
