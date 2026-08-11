<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\AdministrativeFeeRate;
use Modules\Project\Models\Project;

class ScheduleAdminFeePercentageChangeAction
{
    private const HISTORY_SENTINEL_DATE = '2000-01-01';

    public function __construct(
        private readonly ResolveEffectiveAdminFeePercentageAction $resolveEffectiveAdminFeePercentageAction,
    ) {}

    public function execute(Project $project, float $newPercentage, CarbonInterface|string|null $today = null): void
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $todayDate = $today->toDateString();
        $tomorrowDate = $today->copy()->addDay()->toDateString();
        $percentage = round($newPercentage, 2);

        $effectiveToday = $this->resolveEffectiveAdminFeePercentageAction->execute($project, $today);

        $hasCoveringRate = AdministrativeFeeRate::query()
            ->where('project_id', $project->id)
            ->whereDate('effective_from', '<=', $todayDate)
            ->exists();

        if (! $hasCoveringRate) {
            AdministrativeFeeRate::query()->create([
                'project_id' => $project->id,
                'percentage' => $effectiveToday,
                'effective_from' => self::HISTORY_SENTINEL_DATE,
            ]);
        }

        // Pin today's percentage at the moment of the change so the new value can never
        // bleed into the current day, whatever the open-ended history row holds.
        $this->writeRate($project, $todayDate, $effectiveToday, overwrite: false);
        $this->writeRate($project, $tomorrowDate, $percentage, overwrite: true);
    }

    private function writeRate(Project $project, string $date, float $percentage, bool $overwrite): void
    {
        $existing = AdministrativeFeeRate::query()
            ->where('project_id', $project->id)
            ->whereDate('effective_from', $date)
            ->first();

        if ($existing !== null) {
            if ($overwrite) {
                $existing->update(['percentage' => $percentage]);
            }

            return;
        }

        AdministrativeFeeRate::query()->create([
            'project_id' => $project->id,
            'effective_from' => $date,
            'percentage' => $percentage,
        ]);
    }
}
