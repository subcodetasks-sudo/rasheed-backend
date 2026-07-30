<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Project\Actions\Project\ValidateProjectDeletionAction;
use Modules\Project\Contracts\ProjectDeletionConstraint;
use Modules\Project\Contracts\ProjectDeletionConstraintRegistry;
use Modules\Project\Exceptions\ProjectCannotBeDeletedException;
use Modules\Project\Models\Project;
use Tests\TestCase;

class ValidateProjectDeletionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_registry_is_empty(): void
    {
        $project = Project::factory()->create();
        $action = new ValidateProjectDeletionAction(new ProjectDeletionConstraintRegistry([]));

        $action->execute($project);

        $this->assertTrue(true);
    }

    public function test_throws_when_constraint_blocks_deletion(): void
    {
        $project = Project::factory()->create();

        $constraint = new class implements ProjectDeletionConstraint
        {
            public function blocks(Project $project): ?string
            {
                return 'Project cannot be deleted because journal entries exist.';
            }
        };

        $action = new ValidateProjectDeletionAction(
            new ProjectDeletionConstraintRegistry([$constraint])
        );

        $this->expectException(ProjectCannotBeDeletedException::class);
        $this->expectExceptionMessage('Project cannot be deleted because journal entries exist.');

        $action->execute($project);
    }
}
