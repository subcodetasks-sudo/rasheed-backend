<?php

namespace Modules\Inventory\Actions;

use Modules\Inventory\Enums\InventoryBatchSourceType;
use Modules\Inventory\Models\InventoryBatchConsumption;
use Modules\Inventory\Models\InventoryItem;
use Modules\Project\Exceptions\BusinessException;

class ValidateInventoryItemDeletionAction
{
    public function execute(int $itemId): InventoryItem
    {
        $item = InventoryItem::query()->lockForUpdate()->find($itemId);

        if (! $item) {
            throw new BusinessException(__('messages.inventory_item_not_found'), 404);
        }

        if ($this->hasRelatedData($item)) {
            throw new BusinessException(__('messages.inventory_item_has_related_data'));
        }

        return $item;
    }

    private function hasRelatedData(InventoryItem $item): bool
    {
        if ($item->movements()->exists()) {
            return true;
        }

        if ($item->batches()->where('source_type', InventoryBatchSourceType::Incoming)->exists()) {
            return true;
        }

        return InventoryBatchConsumption::query()
            ->whereHas('batch', fn ($query) => $query->where('inventory_item_id', $item->id))
            ->exists();
    }
}
