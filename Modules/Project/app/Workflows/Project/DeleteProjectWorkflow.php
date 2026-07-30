<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\DeleteProjectAction;
use Modules\Project\Actions\Project\ValidateProjectDeletionAction;
use Modules\Project\Events\ProjectDeleted;
use Modules\Project\Models\Project;

class DeleteProjectWorkflow
{
    public function __construct(
        private readonly ValidateProjectDeletionAction $validateProjectDeletionAction,
        private readonly DeleteProjectAction $deleteProjectAction,
    ) {}

    public function handle(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $this->validateProjectDeletionAction->execute($project);

            $projectId = $project->id;
            $projectName = $project->name;

            $this->deleteProjectAction->execute($project);

            ProjectDeleted::dispatch($projectId, $projectName);
        });
    }
}
