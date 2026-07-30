<?php

namespace Modules\Project\Workflows\Category;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Category\UpdateCategoryAction;
use Modules\Project\Models\Category;

class UpdateCategoryWorkflow
{
    public function __construct(
        private readonly UpdateCategoryAction $updateCategoryAction,
    ) {}

    public function handle(Category $category, array $data): Category
    {
        return DB::transaction(fn () => $this->updateCategoryAction->execute($category, $data));
    }
}
