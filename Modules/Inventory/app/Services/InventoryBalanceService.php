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
     * Low-stock state is read live by the dashboard (`low_stock_items`).
     * Kept as a hook after balance changes; do not invent a notification module here.
     */
    public function checkMinimumStock(InventoryItem $item): void
    {
        // Intentionally empty — dashboard queries current_balance <= minimum_stock_level.
    }
}
