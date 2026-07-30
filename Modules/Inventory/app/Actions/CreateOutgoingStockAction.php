<?php

namespace Modules\Inventory\Actions;

use Modules\Inventory\DTOs\OutgoingStockData;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryFifoService;
use Modules\Project\Exceptions\BusinessException;
use Modules\Project\Models\Project;

class CreateOutgoingStockAction
{
    public function __construct(
        private readonly InventoryFifoService $fifoService,
    ) {}

    public function execute(OutgoingStockData $data, ?string $createdBy): InventoryMovement
    {
        $item = InventoryItem::query()->find($data->inventoryItemId);

        if (! $item) {
            throw new BusinessException(__('messages.inventory_item_not_found'), 404);
        }

        if (! Project::query()->whereKey($data->beneficiaryProjectId)->exists()) {
            throw new BusinessException(__('messages.inventory_beneficiary_project_not_found'), 422);
        }

        return $this->fifoService->createOutgoing(
            $item,
            $data->quantity,
            $data->beneficiaryProjectId,
            $data->expenseType,
            $data->notes,
            $createdBy,
        );
    }
}
