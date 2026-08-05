<?php

namespace Modules\Notifications\Listeners;

use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;

class RefreshMonthlySummaryOnCashStationUpdate
{
    public function __construct(
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ) {}

    public function handle(
        CashStationSettlementCreated|CashStationSettlementDeleted|CashStationCarriedForward $event,
    ): void {
        if ($event instanceof CashStationSettlementCreated) {
            $year = (int) $event->settlement->year;
            $month = (int) $event->settlement->month;
        } elseif ($event instanceof CashStationSettlementDeleted) {
            $year = (int) $event->year;
            $month = (int) $event->month;
        } else {
            $year = (int) $event->carry->to_year;
            $month = (int) $event->carry->to_month;
        }

        MonthlySummaryUpdated::dispatch(
            $year,
            $month,
            $this->buildMonthlySummaryAction->execute($month, $year),
        );
    }
}
