<?php

namespace Modules\Inventory\Actions\Category;

use Modules\Inventory\Models\InventoryCategory;

class CreateInventoryCategoryAction
{
    public function execute(array $data): InventoryCategory
    {
        return InventoryCategory::query()->create($data);
    }
}
