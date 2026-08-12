<?php

namespace Modules\Notifications\Listeners;

use Modules\DailyJournal\Actions\RecalculateDailyJournalAction;
use Modules\DailyJournal\Actions\RecalculateDailyJournalForwardChainAction;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Events\InventoryStockMoved;

class RefreshDailyJournalOnInventoryAdministrativeMovement
{
    public function __construct(
        private readonly RecalculateDailyJournalAction $recalculateDailyJournalAction,
        private readonly RecalculateDailyJournalForwardChainAction $recalculateDailyJournalForwardChainAction,
    ) {}

    public function handle(InventoryStockMoved $event): void
    {
        $movement = $event->movement;

        if ($movement->expense_type !== InventoryExpenseType::Administrative) {
            return;
        }

        $date = $movement->movement_date->copy()->startOfDay();

        $result = $this->recalculateDailyJournalAction->execute($date);
        $this->recalculateDailyJournalForwardChainAction->execute($date);

        // Recompute derived modules via the existing DailyJournalUpdated refresh listeners.
        DailyJournalUpdated::dispatch($date, $result->entries);
    }
}
