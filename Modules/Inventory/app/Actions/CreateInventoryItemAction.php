<?php

namespace Modules\Inventory\Actions;

use Modules\Inventory\DTOs\CreateInventoryItemData;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Services\InventoryBalanceService;
use Modules\Inventory\Services\InventoryFifoService;
use Modules\Inventory\Services\InventoryItemCodeGenerator;

class CreateInventoryItemAction
{
    public function __construct(
        private readonly InventoryItemCodeGenerator $codeGenerator,
        private readonly InventoryFifoService $fifoService,
        private readonly InventoryBalanceService $balanceService,
    ) {}

    public function execute(CreateInventoryItemData $data, ?string $createdBy): InventoryItem
    {
        $openingQuantity = round($data->openingQuantity, 2);
        $openingPrice = round($data->openingPrice, 2);

        $item = InventoryItem::query()->create([
            'code' => $this->codeGenerator->generate(),
            'project_id' => $data->projectId,
            'name' => $data->name,
            'inventory_category_id' => $data->categoryId,
            'unit' => $data->unit,
            'latest_incoming_price' => $openingPrice,
            'opening_quantity' => $openingQuantity,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => $openingQuantity,
            'minimum_stock_level' => round($data->minimumStockLevel, 2),
            'notes' => $data->notes,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        $this->balanceService->recompute($item);
        $item->save();

        $this->fifoService->createOpeningBatch($item, $openingQuantity, $openingPrice);
        $this->balanceService->checkMinimumStock($item, null);

        return $item->fresh(['project', 'inventoryCategory']);
    }
}
