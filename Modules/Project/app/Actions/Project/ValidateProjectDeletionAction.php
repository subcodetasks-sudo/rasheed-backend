<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Contracts\ProjectDeletionConstraintRegistry;
use Modules\Project\Exceptions\ProjectCannotBeDeletedException;
use Modules\Project\Models\Project;

class ValidateProjectDeletionAction
{
    public function __construct(
        private readonly ProjectDeletionConstraintRegistry $registry,
    ) {}

    public function execute(Project $project): void
    {
        $reason = $this->registry->firstBlockingReason($project);

        if ($reason !== null) {
            throw new ProjectCannotBeDeletedException($reason);
        }
    }
}
