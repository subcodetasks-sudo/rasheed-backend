<?php

namespace Modules\Project\Workflows\Category;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Category\DeleteCategoryAction;
use Modules\Project\Actions\Project\ValidateProjectDeletionAction;
use Modules\Project\Models\Category;
use Modules\Project\Workflows\Project\DeleteProjectWorkflow;

class DeleteCategoryWorkflow
{
    public function __construct(
        private readonly ValidateProjectDeletionAction $validateProjectDeletionAction,
        private readonly DeleteProjectWorkflow $deleteProjectWorkflow,
        private readonly DeleteCategoryAction $deleteCategoryAction,
    ) {}

    public function handle(Category $category): void
    {
        DB::transaction(function () use ($category) {
            $projects = $category->projects()->get();

            foreach ($projects as $project) {
                $this->validateProjectDeletionAction->execute($project);
            }

            foreach ($projects as $project) {
                $this->deleteProjectWorkflow->handle($project);
            }

            $this->deleteCategoryAction->execute($category);
        });
    }
}
