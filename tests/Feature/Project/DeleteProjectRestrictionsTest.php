<?php

namespace Tests\Feature\Project;

use Modules\Project\Contracts\ProjectDeletionConstraint;
use Modules\Project\Contracts\ProjectDeletionConstraintRegistry;
use Modules\Project\Models\Project;

class DeleteProjectRestrictionsTest extends ProjectFeatureTestCase
{
    // Real finance/journal/inventory-backed blockers are not implemented yet; these tests lock the current registry contract.

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsSuperAdmin(['delete-projects']);
    }

    public function test_delete_succeeds_when_registry_has_no_blockers(): void
    {
        $project = Project::factory()->create();

        $this->app->instance(ProjectDeletionConstraintRegistry::class, new ProjectDeletionConstraintRegistry([]));

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_delete_is_blocked_for_each_requested_dependency_message_via_registry_contract(): void
    {
        $messages = [
            'Project cannot be deleted because a current financial balance exists.',
            'Project cannot be deleted because administrative debt exists.',
            'Project cannot be deleted because financial obligations exist.',
            'Project cannot be deleted because incoming contributions exist.',
            'Project cannot be deleted because outgoing contributions exist.',
            'Project cannot be deleted because journal entries exist.',
            'Project cannot be deleted because inventory movements exist.',
            'Project cannot be deleted because operational records exist.',
            'Project cannot be deleted because financial records exist.',
            'Project cannot be deleted because historical report dependencies exist.',
        ];

        foreach ($messages as $index => $message) {
            $project = Project::factory()->create(['name' => "Blocked {$index}"]);

            $constraint = new class($message) implements ProjectDeletionConstraint
            {
                public function __construct(private readonly string $message) {}

                public function blocks(Project $project): ?string
                {
                    return $this->message;
                }
            };

            $this->app->instance(
                ProjectDeletionConstraintRegistry::class,
                new ProjectDeletionConstraintRegistry([$constraint])
            );

            $this->deleteJson("/api/v1/projects/{$project->id}")
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', $message);

            $this->assertDatabaseHas('projects', ['id' => $project->id]);
        }
    }
}
