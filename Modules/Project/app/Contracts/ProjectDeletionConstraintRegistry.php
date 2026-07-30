<?php

namespace Modules\Project\Contracts;

use Modules\Project\Models\Project;

class ProjectDeletionConstraintRegistry
{
    /**
     * @param  iterable<ProjectDeletionConstraint>  $constraints
     */
    public function __construct(
        private readonly iterable $constraints = [],
    ) {}

    public function firstBlockingReason(Project $project): ?string
    {
        foreach ($this->constraints as $constraint) {
            $reason = $constraint->blocks($project);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
