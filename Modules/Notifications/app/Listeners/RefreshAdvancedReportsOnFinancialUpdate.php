<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\AdvancedReports\Events\AdvancedReportsUpdated;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryMovementDeleted;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Project\Events\ProjectUpdated;

class RefreshAdvancedReportsOnFinancialUpdate
{
    public function handle(
        DailyJournalUpdated|
        AdministrativeDebtRepaid|
        InventoryStockMoved|
        InventoryItemCreated|
        InventoryMovementDeleted|
        ProjectUpdated $event,
    ): void {
        AdvancedReportsUpdated::dispatch($this->resolveAffectedDate($event));
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

        if ($event instanceof InventoryMovementDeleted) {
            return $event->movementDate->toDateString();
        }

        return Carbon::now()->toDateString();
    }
}
