<?php

namespace Modules\Project\Workflows\Category;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Category\CreateCategoryAction;
use Modules\Project\Events\CategoryCreated;
use Modules\Project\Models\Category;

class CreateCategoryWorkflow
{
    public function __construct(
        private readonly CreateCategoryAction $createCategoryAction,
    ) {}

    public function handle(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $category = $this->createCategoryAction->execute($data);
            CategoryCreated::dispatch($category);

            return $category;
        });
    }
}
