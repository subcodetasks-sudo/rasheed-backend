<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Enums\InventoryMovementType;

class InventoryMovementDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $movementId,
        public readonly int $inventoryItemId,
        public readonly string $itemName,
        public readonly InventoryMovementType $type,
        public readonly ?InventoryExpenseType $expenseType,
        public readonly Carbon $movementDate,
        public readonly float $quantity,
    ) {}
}
