<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\AdvancedReports\Events\AdvancedReportsUpdated;
use Modules\Dashboard\Actions\BuildDashboardSummaryAction;
use Modules\Dashboard\Events\DashboardUpdated;
use Modules\OperationalRate\Actions\BuildOperationalRateAction;
use Modules\OperationalRate\Events\OperationalRateUpdated;
use Modules\ReportsCenter\Events\ReportsCenterUpdated;
use Modules\Settings\Events\MonthlyEmployeeSettingsUpdated;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;

class RefreshModulesOnSettingsUpdate
{
    public function __construct(
        private readonly BuildDashboardSummaryAction $buildDashboardSummaryAction,
        private readonly BuildOperationalRateAction $buildOperationalRateAction,
    ) {}

    public function handle(SystemGeneralSettingsUpdated|MonthlyEmployeeSettingsUpdated $event): void
    {
        if ($event instanceof MonthlyEmployeeSettingsUpdated) {
            $year = $event->year;
            $month = $event->month;
            $today = Carbon::now()->startOfDay();
            $affectedDate = ($today->year === $year && $today->month === $month)
                ? $today->toDateString()
                : Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        } else {
            $today = Carbon::now()->startOfDay();
            $year = (int) $today->year;
            $month = (int) $today->month;
            $affectedDate = $today->toDateString();
        }

        $dashboardPayload = $this->buildDashboardSummaryAction->execute(Carbon::parse($affectedDate));
        DashboardUpdated::dispatch($affectedDate, $dashboardPayload);

        OperationalRateUpdated::dispatch(
            $year,
            $month,
            $this->buildOperationalRateAction->execute($month, $year),
        );

        ReportsCenterUpdated::dispatch($affectedDate);
        AdvancedReportsUpdated::dispatch($affectedDate);
    }
}
