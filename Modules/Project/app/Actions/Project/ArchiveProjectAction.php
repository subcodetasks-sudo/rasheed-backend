<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;

class ArchiveProjectAction
{
    public function execute(Project $project): Project
    {
        $project->update([
            'status' => ProjectStatus::Archived,
            'archived_at' => now(),
            'updated_by' => auth()->user()?->uuid,
        ]);

        return $project->fresh(['category', 'creator', 'updater']);
    }
}
