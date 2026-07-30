<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;

class ProjectLifecycleTest extends ProjectFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsSuperAdmin();
    }

    public function test_can_show_project_with_resource_payload(): void
    {
        $category = $this->createCategory(['name' => 'Relief']);
        $project = Project::factory()->create([
            'name' => 'Lifecycle Project',
            'category_id' => $category->id,
        ]);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', 'Lifecycle Project')
            ->assertJsonPath('data.category.name', 'Relief')
            ->assertJsonPath('data.archived_at', null);
    }

    public function test_archive_moves_project_out_of_active_listing_and_restore_brings_it_back(): void
    {
        $category = $this->createCategory();
        $project = Project::factory()->create([
            'name' => 'Archive Me',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed,
            'status' => ProjectStatus::Active,
        ]);

        $this->postJson("/api/v1/projects/{$project->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::Archived->value);

        $this->assertNotNull($project->fresh()->archived_at);

        $this->getJson('/api/v1/projects?tab=fixed')
            ->assertOk();

        $this->getJson('/api/v1/projects?tab=fixed')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/projects?tab=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);

        $this->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::Active->value)
            ->assertJsonPath('data.archived_at', null);

        $this->assertNull($project->fresh()->archived_at);

        $this->getJson('/api/v1/projects?tab=fixed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);

        $this->getJson('/api/v1/projects?tab=archived')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_delete_removes_only_the_selected_project(): void
    {
        $category = $this->createCategory();
        $project = Project::factory()->create([
            'name' => 'Delete Me',
            'category_id' => $category->id,
        ]);

        $otherProject = Project::factory()->create([
            'name' => 'Keep Me',
            'category_id' => $category->id,
        ]);

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('projects', ['id' => $otherProject->id]);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otherProject->id);
    }
}
