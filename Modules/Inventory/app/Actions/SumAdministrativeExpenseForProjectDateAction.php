<?php

namespace Modules\Inventory\Actions;

use Carbon\CarbonInterface;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Models\InventoryMovement;

class SumAdministrativeExpenseForProjectDateAction
{
    /**
     * Aggregate persisted FIFO total_cost only — never recompute FIFO.
     */
    public function execute(int $projectId, CarbonInterface $date): float
    {
        $sum = InventoryMovement::query()
            ->where('type', InventoryMovementType::Outgoing)
            ->where('expense_type', InventoryExpenseType::Administrative)
            ->where('beneficiary_project_id', $projectId)
            ->whereDate('movement_date', $date->toDateString())
            ->sum('total_cost');

        return round((float) $sum, 2);
    }
}
