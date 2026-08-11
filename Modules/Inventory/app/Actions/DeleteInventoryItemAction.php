<?php

namespace Modules\Inventory\Actions;

use Modules\Inventory\Models\InventoryItem;

class DeleteInventoryItemAction
{
    public function execute(InventoryItem $item): void
    {
        $item->delete();
    }
}
