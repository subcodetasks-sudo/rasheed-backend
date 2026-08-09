<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryCategoryDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
    ) {}
}
