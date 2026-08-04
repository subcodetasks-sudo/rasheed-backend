<?php

namespace Modules\Inventory\Workflows\Category;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\Category\UpdateInventoryCategoryAction;
use Modules\Inventory\Models\InventoryCategory;

class UpdateInventoryCategoryWorkflow
{
    public function __construct(
        private readonly UpdateInventoryCategoryAction $updateInventoryCategoryAction,
    ) {}

    public function handle(InventoryCategory $category, array $data): InventoryCategory
    {
        return DB::transaction(
            fn () => $this->updateInventoryCategoryAction->execute($category, $data)
        );
    }
}
