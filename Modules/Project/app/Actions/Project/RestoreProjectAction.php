<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;

class RestoreProjectAction
{
    public function execute(Project $project): Project
    {
        $project->update([
            'status' => ProjectStatus::Active,
            'archived_at' => null,
            'updated_by' => auth()->user()?->uuid,
        ]);

        return $project->fresh(['category', 'creator', 'updater']);
    }
}
