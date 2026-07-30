<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\CreateInventoryItemAction;
use Modules\Inventory\DTOs\CreateInventoryItemData;
use Modules\Inventory\Models\InventoryItem;

class CreateInventoryItemWorkflow
{
    public function __construct(
        private readonly CreateInventoryItemAction $createInventoryItemAction,
    ) {}

    public function handle(CreateInventoryItemData $data): InventoryItem
    {
        return DB::transaction(function () use ($data) {
            return $this->createInventoryItemAction->execute(
                $data,
                auth()->user()?->uuid,
            );
        });
    }
}
