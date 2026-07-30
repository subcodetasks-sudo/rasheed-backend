<?php

namespace Modules\Project\Actions\Category;

use Illuminate\Database\Eloquent\Collection;
use Modules\Project\Models\Category;

class ListCategoriesAction
{
    public function execute(): Collection
    {
        return Category::query()->orderBy('name')->get();
    }
}
