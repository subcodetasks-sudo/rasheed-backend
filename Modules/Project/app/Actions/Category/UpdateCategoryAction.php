<?php

namespace Modules\Project\Actions\Category;

use Modules\Project\Models\Category;

class UpdateCategoryAction
{
    public function execute(Category $category, array $data): Category
    {
        $category->update($data);

        return $category->fresh();
    }
}
