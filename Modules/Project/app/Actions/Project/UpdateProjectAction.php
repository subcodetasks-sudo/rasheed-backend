<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Models\Project;

class UpdateProjectAction
{
    public function execute(Project $project, array $attributes): Project
    {
        $project->update($attributes);

        return $project->fresh(['category', 'creator', 'updater']);
    }
}
