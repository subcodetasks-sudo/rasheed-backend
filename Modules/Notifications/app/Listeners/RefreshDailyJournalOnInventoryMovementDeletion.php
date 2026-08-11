<?php

namespace Modules\Notifications\Listeners;

use Modules\DailyJournal\Actions\RecalculateDailyJournalAction;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Events\InventoryMovementDeleted;

class RefreshDailyJournalOnInventoryMovementDeletion
{
    public function __construct(
        private readonly RecalculateDailyJournalAction $recalculateDailyJournalAction,
    ) {}

    public function handle(InventoryMovementDeleted $event): void
    {
        if ($event->expenseType !== InventoryExpenseType::Administrative) {
            return;
        }

        $date = $event->movementDate->copy()->startOfDay();

        $result = $this->recalculateDailyJournalAction->execute($date);

        // Recompute derived modules via the existing DailyJournalUpdated refresh listeners.
        DailyJournalUpdated::dispatch($date, $result->entries);
    }
}
