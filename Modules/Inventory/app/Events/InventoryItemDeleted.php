<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryItemDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $itemId,
        public readonly string $itemName,
        public readonly string $itemCode,
    ) {}
}
