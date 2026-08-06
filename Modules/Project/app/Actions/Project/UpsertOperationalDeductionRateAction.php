<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Models\OperationalDeductionRate;

class UpsertOperationalDeductionRateAction
{
    public function execute(string $effectiveFrom, float $amount, bool $overwrite = true): void
    {
        $amount = round($amount, 2);

        $existing = OperationalDeductionRate::query()
            ->whereDate('effective_from', $effectiveFrom)
            ->first();

        if ($existing !== null) {
            if ($overwrite) {
                $existing->update(['amount' => $amount]);
            }

            return;
        }

        OperationalDeductionRate::query()->create([
            'effective_from' => $effectiveFrom,
            'amount' => $amount,
        ]);
    }
}
