<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\ArchiveProjectAction;
use Modules\Project\Events\ProjectArchived;
use Modules\Project\Models\Project;

class ArchiveProjectWorkflow
{
    public function __construct(
        private readonly ArchiveProjectAction $archiveProjectAction,
    ) {}

    public function handle(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $archived = $this->archiveProjectAction->execute($project);

            ProjectArchived::dispatch($archived);

            return $archived;
        });
    }
}
