<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InventoryBatchSourceType;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\InventoryBatchConsumption;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Project\Exceptions\BusinessException;

class InventoryFifoService
{
    public function __construct(
        private readonly InventoryBalanceService $balanceService,
    ) {}

    public function createOpeningBatch(InventoryItem $item, float $quantity, float $unitCost): ?InventoryBatch
    {
        if ($quantity <= 0) {
            return null;
        }

        return InventoryBatch::query()->create([
            'inventory_item_id' => $item->id,
            'source_type' => InventoryBatchSourceType::Opening,
            'source_movement_id' => null,
            'unit_cost' => round($unitCost, 2),
            'original_quantity' => round($quantity, 2),
            'remaining_quantity' => round($quantity, 2),
            'received_at' => now(),
        ]);
    }

    public function createIncoming(
        InventoryItem $item,
        float $quantity,
        float $unitPrice,
        ?string $notes,
        ?string $createdBy,
    ): InventoryMovement {
        $quantity = round($quantity, 2);
        $unitPrice = round($unitPrice, 2);
        $movementDate = Carbon::today()->toDateString();

        $movement = InventoryMovement::query()->create([
            'inventory_item_id' => $item->id,
            'type' => InventoryMovementType::Incoming,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_cost' => round($quantity * $unitPrice, 2),
            'beneficiary_project_id' => null,
            'expense_type' => null,
            'notes' => $notes,
            'movement_date' => $movementDate,
            'created_by' => $createdBy,
        ]);

        InventoryBatch::query()->create([
            'inventory_item_id' => $item->id,
            'source_type' => InventoryBatchSourceType::Incoming,
            'source_movement_id' => $movement->id,
            'unit_cost' => $unitPrice,
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'received_at' => now(),
        ]);

        $previousBalance = (float) $item->current_balance;
        $item->total_incoming_quantity = round((float) $item->total_incoming_quantity + $quantity, 2);
        $item->latest_incoming_price = $unitPrice;
        $this->balanceService->recompute($item);
        $item->updated_by = $createdBy;
        $item->save();

        $this->balanceService->checkMinimumStock($item, $previousBalance);

        return $movement->fresh(['item', 'creator']);
    }

    public function createOutgoing(
        InventoryItem $item,
        float $quantity,
        int $beneficiaryProjectId,
        InventoryExpenseType $expenseType,
        ?string $notes,
        ?string $createdBy,
    ): InventoryMovement {
        $quantity = round($quantity, 2);
        $available = (float) $item->current_balance;

        if ($quantity > $available) {
            throw new BusinessException(__('messages.inventory_insufficient_stock'), 422);
        }

        return DB::transaction(function () use ($item, $quantity, $beneficiaryProjectId, $expenseType, $notes, $createdBy) {
            $batches = InventoryBatch::query()
                ->where('inventory_item_id', $item->id)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remainingToConsume = $quantity;
            $consumptions = [];
            $totalCost = 0.0;

            foreach ($batches as $batch) {
                if ($remainingToConsume <= 0) {
                    break;
                }

                $take = min((float) $batch->remaining_quantity, $remainingToConsume);
                $take = round($take, 2);
                $unitCost = (float) $batch->unit_cost;
                $lineCost = round($take * $unitCost, 2);

                $batch->remaining_quantity = round((float) $batch->remaining_quantity - $take, 2);
                $batch->save();

                $consumptions[] = [
                    'batch_id' => $batch->id,
                    'quantity' => $take,
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                ];

                $totalCost = round($totalCost + $lineCost, 2);
                $remainingToConsume = round($remainingToConsume - $take, 2);
            }

            if ($remainingToConsume > 0) {
                throw new BusinessException(__('messages.inventory_insufficient_stock'), 422);
            }

            $unitPrice = $quantity > 0 ? round($totalCost / $quantity, 2) : 0.0;

            $movement = InventoryMovement::query()->create([
                'inventory_item_id' => $item->id,
                'type' => InventoryMovementType::Outgoing,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_cost' => $totalCost,
                'beneficiary_project_id' => $beneficiaryProjectId,
                'expense_type' => $expenseType,
                'notes' => $notes,
                'movement_date' => Carbon::today()->toDateString(),
                'created_by' => $createdBy,
            ]);

            foreach ($consumptions as $row) {
                InventoryBatchConsumption::query()->create([
                    'outgoing_movement_id' => $movement->id,
                    'batch_id' => $row['batch_id'],
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'line_cost' => $row['line_cost'],
                ]);
            }

            $previousBalance = (float) $item->current_balance;
            $item->total_outgoing_quantity = round((float) $item->total_outgoing_quantity + $quantity, 2);
            $this->balanceService->recompute($item);
            $item->updated_by = $createdBy;
            $item->save();

            $this->balanceService->checkMinimumStock($item, $previousBalance);

            return $movement->fresh(['item', 'beneficiaryProject', 'consumptions', 'creator']);
        });
    }
}
