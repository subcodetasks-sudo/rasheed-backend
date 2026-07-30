<?php

namespace Modules\Project\Contracts;

use Modules\Project\Models\Project;

interface ProjectDeletionConstraint
{
    /**
     * Return a human-readable reason when deletion must be blocked, otherwise null.
     */
    public function blocks(Project $project): ?string;
}
