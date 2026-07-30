<?php

namespace Modules\Inventory\Actions;

use Modules\Inventory\DTOs\IncomingStockData;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryFifoService;
use Modules\Project\Exceptions\BusinessException;

class CreateIncomingStockAction
{
    public function __construct(
        private readonly InventoryFifoService $fifoService,
    ) {}

    public function execute(IncomingStockData $data, ?string $createdBy): InventoryMovement
    {
        $item = InventoryItem::query()->find($data->inventoryItemId);

        if (! $item) {
            throw new BusinessException(__('messages.inventory_item_not_found'), 404);
        }

        return $this->fifoService->createIncoming(
            $item,
            $data->quantity,
            $data->unitPrice,
            $data->notes,
            $createdBy,
        );
    }
}
