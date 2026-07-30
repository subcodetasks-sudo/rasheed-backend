<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\RestoreProjectAction;
use Modules\Project\Events\ProjectRestored;
use Modules\Project\Models\Project;

class RestoreProjectWorkflow
{
    public function __construct(
        private readonly RestoreProjectAction $restoreProjectAction,
    ) {}

    public function handle(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $restored = $this->restoreProjectAction->execute($project);

            ProjectRestored::dispatch($restored);

            return $restored;
        });
    }
}
