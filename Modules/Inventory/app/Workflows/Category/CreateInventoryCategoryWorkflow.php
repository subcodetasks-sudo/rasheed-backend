<?php

namespace Modules\Inventory\Workflows\Category;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\Category\CreateInventoryCategoryAction;
use Modules\Inventory\Models\InventoryCategory;

class CreateInventoryCategoryWorkflow
{
    public function __construct(
        private readonly CreateInventoryCategoryAction $createInventoryCategoryAction,
    ) {}

    public function handle(array $data): InventoryCategory
    {
        return DB::transaction(fn () => $this->createInventoryCategoryAction->execute($data));
    }
}
