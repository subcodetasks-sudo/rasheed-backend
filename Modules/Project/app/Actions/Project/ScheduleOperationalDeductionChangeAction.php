<?php

namespace Modules\Project\Actions\Project;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Project\Models\OperationalDeductionRate;

class ScheduleOperationalDeductionChangeAction
{
    private const HISTORY_SENTINEL_DATE = '2000-01-01';

    public function __construct(
        private readonly ResolveEffectiveOperationalDeductionAction $resolveEffectiveOperationalDeductionAction,
    ) {}

    public function execute(float $newAmount, CarbonInterface|string|null $today = null): void
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $todayDate = $today->toDateString();
        $tomorrowDate = $today->copy()->addDay()->toDateString();
        $amount = round($newAmount, 2);

        $effectiveToday = $this->resolveEffectiveOperationalDeductionAction->execute($today);

        $hasCoveringRate = OperationalDeductionRate::query()
            ->whereDate('effective_from', '<=', $todayDate)
            ->exists();

        if (! $hasCoveringRate) {
            OperationalDeductionRate::query()->create([
                'amount' => $effectiveToday,
                'effective_from' => self::HISTORY_SENTINEL_DATE,
            ]);
        }

        // Pin today's pool at the moment of the change so the new amount can never
        // bleed into the current day, whatever the open-ended history row holds.
        $this->writeRate($todayDate, $effectiveToday, overwrite: false);
        $this->writeRate($tomorrowDate, $amount, overwrite: true);
    }

    private function writeRate(string $date, float $amount, bool $overwrite): void
    {
        $existing = OperationalDeductionRate::query()
            ->whereDate('effective_from', $date)
            ->first();

        if ($existing !== null) {
            if ($overwrite) {
                $existing->update(['amount' => $amount]);
            }

            return;
        }

        OperationalDeductionRate::query()->create([
            'effective_from' => $date,
            'amount' => $amount,
        ]);
    }
}
