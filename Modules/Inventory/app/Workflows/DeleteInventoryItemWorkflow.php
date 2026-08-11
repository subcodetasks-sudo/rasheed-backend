<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\DeleteInventoryItemAction;
use Modules\Inventory\Events\InventoryItemDeleted;
use Modules\Inventory\Models\InventoryItem;
use Modules\Project\Exceptions\BusinessException;

class DeleteInventoryItemWorkflow
{
    public function __construct(
        private readonly DeleteInventoryItemAction $deleteInventoryItemAction,
    ) {}

    public function handle(int $itemId): void
    {
        DB::transaction(function () use ($itemId) {
            $item = InventoryItem::query()->find($itemId);

            if (! $item) {
                throw new BusinessException(__('messages.inventory_item_not_found'), 404);
            }

            if ($item->movements()->exists()) {
                throw new BusinessException(__('messages.inventory_item_has_movements'));
            }

            if ($item->batches()->exists()) {
                throw new BusinessException(__('messages.inventory_item_has_batches'));
            }

            $itemId = $item->id;
            $itemName = $item->name;
            $itemCode = $item->code;

            $this->deleteInventoryItemAction->execute($item);

            InventoryItemDeleted::dispatch($itemId, $itemName, $itemCode);
        });
    }
}
