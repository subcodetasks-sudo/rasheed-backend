<?php

namespace Modules\CashStation\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\CarryForwardMonthAction;
use Modules\CashStation\Models\CashStationMonthCarry;

class CarryForwardCashStationWorkflow
{
    public function __construct(
        private readonly CarryForwardMonthAction $carryForwardMonthAction,
    ) {}

    public function handle(int $month, int $year): CashStationMonthCarry
    {
        return DB::transaction(function () use ($month, $year) {
            return $this->carryForwardMonthAction->execute(
                $month,
                $year,
                auth()->user()?->uuid,
            );
        });
    }
}
