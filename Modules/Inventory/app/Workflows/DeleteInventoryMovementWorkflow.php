<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Events\InventoryMovementDeleted;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\InventoryBatchConsumption;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryBalanceService;
use Modules\Project\Exceptions\BusinessException;

class DeleteInventoryMovementWorkflow
{
    public function __construct(
        private readonly InventoryBalanceService $balanceService,
    ) {}

    public function handle(int $movementId, ?string $deletedBy = null): InventoryItem
    {
        [$item, $eventArgs] = DB::transaction(function () use ($movementId, $deletedBy) {
            $movement = InventoryMovement::query()->find($movementId);

            if (! $movement) {
                throw new BusinessException(__('messages.inventory_movement_not_found'), 404);
            }

            $item = InventoryItem::query()->lockForUpdate()->find($movement->inventory_item_id);

            if (! $item) {
                throw new BusinessException(__('messages.inventory_item_not_found'), 404);
            }

            $type = $movement->type;
            $expenseType = $movement->expense_type;
            $movementDate = $movement->movement_date->copy();
            $quantity = (float) $movement->quantity;
            $itemName = $item->name;

            $previousBalance = (float) $item->current_balance;

            if ($type === InventoryMovementType::Incoming) {
                $this->reverseIncoming($item, $movement, $quantity);
            } else {
                $this->reverseOutgoing($item, $movement, $quantity);
            }

            $movement->delete();

            $this->balanceService->recompute($item);
            $item->updated_by = $deletedBy;
            $item->save();

            $this->balanceService->checkMinimumStock($item, $previousBalance);

            return [
                $item,
                [$movementId, $item->id, $itemName, $type, $expenseType, $movementDate, $quantity],
            ];
        });

        InventoryMovementDeleted::dispatch(...$eventArgs);

        return $item;
    }

    private function reverseIncoming(InventoryItem $item, InventoryMovement $movement, float $quantity): void
    {
        $batch = InventoryBatch::query()
            ->where('source_movement_id', $movement->id)
            ->lockForUpdate()
            ->first();

        if ($batch && round((float) $batch->remaining_quantity, 2) !== round((float) $batch->original_quantity, 2)) {
            throw new BusinessException(__('messages.inventory_movement_already_consumed'));
        }

        $batch?->delete();

        $item->total_incoming_quantity = round((float) $item->total_incoming_quantity - $quantity, 2);
    }

    private function reverseOutgoing(InventoryItem $item, InventoryMovement $movement, float $quantity): void
    {
        $consumptions = InventoryBatchConsumption::query()
            ->where('outgoing_movement_id', $movement->id)
            ->get();

        $batchIds = $consumptions->pluck('batch_id')->unique()->all();
        $batches = InventoryBatch::query()
            ->whereIn('id', $batchIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($consumptions as $consumption) {
            $batch = $batches->get($consumption->batch_id);

            if ($batch) {
                $batch->remaining_quantity = round((float) $batch->remaining_quantity + (float) $consumption->quantity, 2);
                $batch->save();
            }

            $consumption->delete();
        }

        $item->total_outgoing_quantity = round((float) $item->total_outgoing_quantity - $quantity, 2);
    }
}
