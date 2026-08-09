<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\InventoryCategory;

class InventoryCategoryCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public InventoryCategory $category) {}
}
