<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryItem;
use Modules\Notifications\Support\NotificationRuleRegistry;

class InventoryBalanceService
{
    public function __construct(
        private readonly NotificationRuleRegistry $notificationRuleRegistry,
    ) {}

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
     * Evaluate tagged stock notification rules after balance changes.
     */
    public function checkMinimumStock(InventoryItem $item, ?float $previousBalance = null): void
    {
        $this->notificationRuleRegistry->evaluate('inventory.stock', $item, [
            'previous_balance' => $previousBalance,
        ]);
    }
}
