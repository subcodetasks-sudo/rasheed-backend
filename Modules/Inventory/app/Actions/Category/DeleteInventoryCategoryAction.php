<?php

namespace Modules\Inventory\Actions\Category;

use Modules\Inventory\Models\InventoryCategory;

class DeleteInventoryCategoryAction
{
    public function execute(InventoryCategory $category): void
    {
        $category->delete();
    }
}
