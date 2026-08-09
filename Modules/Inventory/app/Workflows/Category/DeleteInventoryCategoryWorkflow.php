<?php

namespace Modules\Inventory\Workflows\Category;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\Category\DeleteInventoryCategoryAction;
use Modules\Inventory\Events\InventoryCategoryDeleted;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Project\Exceptions\BusinessException;

class DeleteInventoryCategoryWorkflow
{
    public function __construct(
        private readonly DeleteInventoryCategoryAction $deleteInventoryCategoryAction,
    ) {}

    public function handle(InventoryCategory $category): void
    {
        DB::transaction(function () use ($category) {
            if ($category->items()->exists()) {
                throw new BusinessException(__('messages.inventory_category_has_items'));
            }

            $categoryId = (int) $category->id;
            $categoryName = (string) $category->name;
            $this->deleteInventoryCategoryAction->execute($category);
            InventoryCategoryDeleted::dispatch($categoryId, $categoryName);
        });
    }
}
