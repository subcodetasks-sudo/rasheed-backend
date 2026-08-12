<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\DeleteInventoryItemAction;
use Modules\Inventory\Actions\ValidateInventoryItemDeletionAction;
use Modules\Inventory\Enums\InventoryBatchSourceType;
use Modules\Inventory\Events\InventoryItemDeleted;

class DeleteInventoryItemWorkflow
{
    public function __construct(
        private readonly ValidateInventoryItemDeletionAction $validateInventoryItemDeletionAction,
        private readonly DeleteInventoryItemAction $deleteInventoryItemAction,
    ) {}

    public function handle(int $itemId): void
    {
        [$deletedId, $itemName, $itemCode] = DB::transaction(function () use ($itemId) {
            $item = $this->validateInventoryItemDeletionAction->execute($itemId);

            $deletedId = $item->id;
            $itemName = $item->name;
            $itemCode = $item->code;

            $item->batches()
                ->where('source_type', InventoryBatchSourceType::Opening)
                ->delete();

            $this->deleteInventoryItemAction->execute($item);

            return [$deletedId, $itemName, $itemCode];
        });

        InventoryItemDeleted::dispatch($deletedId, $itemName, $itemCode);
    }
}
