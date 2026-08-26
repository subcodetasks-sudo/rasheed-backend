<?php

namespace Modules\CashStation\Actions;

use Carbon\Carbon;
use Modules\CashStation\Models\CashStationMonthCarry;

class CarryForwardMonthAction
{
    public function __construct(
        private readonly AssertFinancialMonthUnlockedAction $assertFinancialMonthUnlockedAction,
    ) {}

    public function execute(int $month, int $year, ?string $carriedBy = null): CashStationMonthCarry
    {
        $source = Carbon::create($year, $month, 1)->startOfDay();
        $target = $source->copy()->addMonthNoOverflow();

        // Carry-from a locked month is allowed; carrying into a locked target is not.
        $this->assertFinancialMonthUnlockedAction->execute(
            (int) $target->year,
            (int) $target->month,
        );

        return CashStationMonthCarry::query()->updateOrCreate(
            [
                'from_year' => $year,
                'from_month' => $month,
            ],
            [
                'to_year' => (int) $target->year,
                'to_month' => (int) $target->month,
                'carried_by' => $carriedBy,
            ],
        );
    }
}
