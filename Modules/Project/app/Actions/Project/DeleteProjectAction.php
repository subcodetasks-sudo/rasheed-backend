<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Models\Project;

class DeleteProjectAction
{
    public function execute(Project $project): void
    {
        $project->forceDelete();
    }
}
