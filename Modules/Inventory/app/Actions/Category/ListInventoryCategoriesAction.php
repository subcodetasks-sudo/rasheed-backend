<?php

namespace Modules\Inventory\Actions\Category;

use Illuminate\Database\Eloquent\Collection;
use Modules\Inventory\Models\InventoryCategory;

class ListInventoryCategoriesAction
{
    public function execute(): Collection
    {
        return InventoryCategory::query()->orderBy('name')->get();
    }
}
