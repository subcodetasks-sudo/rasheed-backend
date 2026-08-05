<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Project\Events\ProjectUpdated;
use Modules\ReportsCenter\Events\ReportsCenterUpdated;

class RefreshReportsCenterOnFinancialUpdate
{
    public function handle(
        DailyJournalUpdated|
        AdministrativeDebtRepaid|
        InventoryStockMoved|
        CashStationSettlementCreated|
        CashStationSettlementDeleted|
        CashStationCarriedForward|
        AdministrativeDebtSettlementCreated|
        ProjectUpdated $event,
    ): void {
        ReportsCenterUpdated::dispatch($this->resolveAffectedDate($event));
    }

    private function resolveAffectedDate(object $event): string
    {
        if ($event instanceof DailyJournalUpdated) {
            return $event->journalDate->toDateString();
        }

        if ($event instanceof AdministrativeDebtRepaid) {
            return Carbon::parse($event->entry->journal_date)->toDateString();
        }

        if ($event instanceof InventoryStockMoved) {
            return Carbon::parse($event->movement->movement_date)->toDateString();
        }

        if ($event instanceof CashStationSettlementCreated) {
            return Carbon::create(
                (int) $event->settlement->year,
                (int) $event->settlement->month,
                1,
            )->endOfMonth()->toDateString();
        }

        if ($event instanceof CashStationSettlementDeleted) {
            return Carbon::create($event->year, $event->month, 1)->endOfMonth()->toDateString();
        }

        if ($event instanceof CashStationCarriedForward) {
            return Carbon::create(
                (int) $event->carry->to_year,
                (int) $event->carry->to_month,
                1,
            )->endOfMonth()->toDateString();
        }

        if ($event instanceof AdministrativeDebtSettlementCreated) {
            return Carbon::create(
                (int) $event->settlement->year,
                (int) $event->settlement->month,
                1,
            )->endOfMonth()->toDateString();
        }

        return Carbon::now()->toDateString();
    }
}
