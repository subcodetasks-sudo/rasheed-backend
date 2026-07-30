<?php

namespace Tests\Feature\Project;

use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Contracts\ProjectDeletionConstraint;
use Modules\Project\Contracts\ProjectDeletionConstraintRegistry;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;

class DeleteCategoryRespectsProjectRulesTest extends ProjectFeatureTestCase
{
    public function test_cannot_delete_category_when_project_has_journal_entries(): void
    {
        $this->actAsSuperAdmin();

        $category = Category::factory()->create();
        $project = Project::factory()->create(['category_id' => $category->id]);
        DailyJournalEntry::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('messages.project_has_journal_entries'));

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_cannot_delete_category_when_any_project_is_blocked_and_nothing_is_deleted(): void
    {
        $this->actAsSuperAdmin();

        $constraint = new class implements ProjectDeletionConstraint
        {
            public function blocks(Project $project): ?string
            {
                if ($project->name === 'Blocked Project') {
                    return 'Project cannot be deleted because administrative debt exists.';
                }

                return null;
            }
        };

        $this->app->instance(
            ProjectDeletionConstraintRegistry::class,
            new ProjectDeletionConstraintRegistry([$constraint])
        );

        $category = Category::factory()->create();
        $deletable = Project::factory()->create([
            'category_id' => $category->id,
            'name' => 'Deletable Project',
        ]);
        $blocked = Project::factory()->create([
            'category_id' => $category->id,
            'name' => 'Blocked Project',
        ]);

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Project cannot be deleted because administrative debt exists.');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('projects', ['id' => $deletable->id]);
        $this->assertDatabaseHas('projects', ['id' => $blocked->id]);
    }

    public function test_can_delete_category_with_only_deletable_projects(): void
    {
        $this->actAsSuperAdmin();

        $this->app->instance(
            ProjectDeletionConstraintRegistry::class,
            new ProjectDeletionConstraintRegistry([])
        );

        $category = Category::factory()->create();
        $first = Project::factory()->create(['category_id' => $category->id, 'name' => 'First Child']);
        $second = Project::factory()->create(['category_id' => $category->id, 'name' => 'Second Child']);

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('projects', ['id' => $first->id]);
        $this->assertDatabaseMissing('projects', ['id' => $second->id]);
    }

    public function test_can_delete_empty_category(): void
    {
        $this->actAsSuperAdmin();

        $category = Category::factory()->create();

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}
