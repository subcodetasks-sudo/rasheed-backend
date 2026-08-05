<?php

namespace Modules\MonthlySummary\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Actions\CarryForwardMonthAction;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;

class CarryForwardMonthlySummaryWorkflow
{
    public function __construct(
        private readonly CarryForwardMonthAction $carryForwardMonthAction,
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ) {}

    public function handle(int $month, int $year): CashStationMonthCarry
    {
        $carry = DB::transaction(function () use ($month, $year) {
            return $this->carryForwardMonthAction->execute(
                $month,
                $year,
                auth()->user()?->uuid,
            );
        });

        CashStationCarriedForward::dispatch($carry);
        CashStationUpdated::dispatch(
            $carry->to_year,
            $carry->to_month,
            $this->buildCashStationAction->execute($carry->to_month, $carry->to_year),
        );
        MonthlySummaryUpdated::dispatch(
            $carry->to_year,
            $carry->to_month,
            $this->buildMonthlySummaryAction->execute($carry->to_month, $carry->to_year),
        );

        return $carry;
    }
}
