<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryItem;

class InventoryBalanceService
{
    public function recompute(InventoryItem $item): InventoryItem
    {
        $item->current_balance = round(
            (float) $item->opening_quantity
            + (float) $item->total_incoming_quantity
            - (float) $item->total_outgoing_quantity,
            2
        );

        return $item;
    }

    /**
     * TODO: trigger notification when a notification module exists.
     * Do not invent notification infrastructure here.
     */
    public function checkMinimumStock(InventoryItem $item): void
    {
        if ((float) $item->current_balance <= (float) $item->minimum_stock_level) {
            // TODO: notify when notification module exists
        }
    }
}
