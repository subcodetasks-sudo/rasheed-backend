<?php

namespace Modules\Project\Actions\Category;

use Modules\Project\Models\Category;

class DeleteCategoryAction
{
    public function execute(Category $category): void
    {
        $category->delete();
    }
}
