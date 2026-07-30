<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Project\Contracts\ProjectDeletionConstraint;
use Modules\Project\Contracts\ProjectDeletionConstraintRegistry;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeleteProjectBlockedTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_returns_business_exception_when_constraint_blocks(): void
    {
        $role = Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $constraint = new class implements ProjectDeletionConstraint
        {
            public function blocks(Project $project): ?string
            {
                return 'Project cannot be deleted because journal entries exist.';
            }
        };

        $this->app->instance(
            ProjectDeletionConstraintRegistry::class,
            new ProjectDeletionConstraintRegistry([$constraint])
        );

        $project = Project::factory()->create();

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Project cannot be deleted because journal entries exist.');

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}
