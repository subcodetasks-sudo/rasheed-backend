<?php

namespace Modules\Inventory\Actions\Category;

use Modules\Inventory\Models\InventoryCategory;

class UpdateInventoryCategoryAction
{
    public function execute(InventoryCategory $category, array $data): InventoryCategory
    {
        $category->update($data);

        return $category->fresh();
    }
}
