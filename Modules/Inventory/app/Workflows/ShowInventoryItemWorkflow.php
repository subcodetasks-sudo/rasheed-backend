<?php

namespace Modules\Inventory\Workflows;

use Modules\Inventory\Models\InventoryItem;
use Modules\Project\Exceptions\BusinessException;

class ShowInventoryItemWorkflow
{
    public function handle(int $itemId): InventoryItem
    {
        $item = InventoryItem::query()->with(['project', 'inventoryCategory'])->find($itemId);

        if (! $item) {
            throw new BusinessException(__('messages.inventory_item_not_found'), 404);
        }

        return $item;
    }
}
