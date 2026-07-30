<?php

namespace Modules\Project\Workflows\Project;

use Modules\Project\Models\Project;

class ShowProjectWorkflow
{
    public function handle(Project $project): Project
    {
        return $project->loadMissing(['category', 'creator', 'updater']);
    }
}
