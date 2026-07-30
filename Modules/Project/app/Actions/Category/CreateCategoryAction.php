<?php

namespace Modules\Project\Actions\Category;

use Modules\Project\Models\Category;

class CreateCategoryAction
{
    public function execute(array $data): Category
    {
        return Category::create($data);
    }
}
