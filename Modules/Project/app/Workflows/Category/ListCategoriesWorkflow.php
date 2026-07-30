<?php

namespace Modules\Project\Workflows\Category;

use Illuminate\Database\Eloquent\Collection;
use Modules\Project\Actions\Category\ListCategoriesAction;

class ListCategoriesWorkflow
{
    public function __construct(
        private readonly ListCategoriesAction $listCategoriesAction,
    ) {}

    public function handle(): Collection
    {
        return $this->listCategoriesAction->execute();
    }
}
