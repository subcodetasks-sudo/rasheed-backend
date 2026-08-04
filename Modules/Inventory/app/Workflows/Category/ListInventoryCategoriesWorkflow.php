<?php

namespace Modules\Inventory\Workflows\Category;

use Illuminate\Database\Eloquent\Collection;
use Modules\Inventory\Actions\Category\ListInventoryCategoriesAction;

class ListInventoryCategoriesWorkflow
{
    public function __construct(
        private readonly ListInventoryCategoriesAction $listInventoryCategoriesAction,
    ) {}

    public function handle(): Collection
    {
        return $this->listInventoryCategoriesAction->execute();
    }
}
