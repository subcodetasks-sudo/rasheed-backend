<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Dashboard\Actions\BuildDashboardSummaryAction;
use Modules\Dashboard\Events\DashboardUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\OperationalFund\Events\OperationalFundUpdated;
use Modules\Project\Events\ProjectUpdated;

class RefreshDashboardOnFinancialUpdate
{
    public function __construct(
        private readonly BuildDashboardSummaryAction $buildDashboardSummaryAction,
    ) {}

    public function handle(
        DailyJournalUpdated|
        AdministrativeDebtRepaid|
        InventoryStockMoved|
        InventoryItemCreated|
        CashStationSettlementCreated|
        CashStationSettlementDeleted|
        CashStationCarriedForward|
        AdministrativeDebtSettlementCreated|
        ProjectUpdated|
        OperationalFundUpdated $event,
    ): void {
        $journalDate = $this->resolveAffectedDate($event);
        $payload = $this->buildDashboardSummaryAction->execute(Carbon::parse($journalDate));

        DashboardUpdated::dispatch($journalDate, $payload);
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

        if ($event instanceof OperationalFundUpdated) {
            return Carbon::create($event->year, $event->month, 1)->endOfMonth()->toDateString();
        }

        return Carbon::now()->toDateString();
    }
}
