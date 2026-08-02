<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\AdministrativeFeeRate;

class ScheduleAdminFeePercentageChangeAction
{
    private const HISTORY_SENTINEL_DATE = '2000-01-01';

    public function __construct(
        private readonly ResolveEffectiveAdminFeePercentageAction $resolveEffectiveAdminFeePercentageAction,
    ) {}

    public function execute(float $newPercentage, CarbonInterface|string|null $today = null): void
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $todayDate = $today->toDateString();
        $tomorrowDate = $today->copy()->addDay()->toDateString();
        $percentage = round($newPercentage, 2);

        $effectiveToday = $this->resolveEffectiveAdminFeePercentageAction->execute($today);

        $hasCoveringRate = AdministrativeFeeRate::query()
            ->whereDate('effective_from', '<=', $todayDate)
            ->exists();

        if (! $hasCoveringRate) {
            AdministrativeFeeRate::query()->create([
                'percentage' => $effectiveToday,
                'effective_from' => self::HISTORY_SENTINEL_DATE,
            ]);
        }

        // Pin today's percentage at the moment of the change so the new value can never
        // bleed into the current day, whatever the open-ended history row holds.
        $this->writeRate($todayDate, $effectiveToday, overwrite: false);
        $this->writeRate($tomorrowDate, $percentage, overwrite: true);
    }

    private function writeRate(string $date, float $percentage, bool $overwrite): void
    {
        $existing = AdministrativeFeeRate::query()
            ->whereDate('effective_from', $date)
            ->first();

        if ($existing !== null) {
            if ($overwrite) {
                $existing->update(['percentage' => $percentage]);
            }

            return;
        }

        AdministrativeFeeRate::query()->create([
            'effective_from' => $date,
            'percentage' => $percentage,
        ]);
    }
}
