<?php

namespace Tests\Feature\Project;

use Modules\Project\Models\Project;

class ProjectAuthorizationTest extends ProjectFeatureTestCase
{
    public function test_project_endpoints_require_authentication(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->postJson('/api/v1/projects', [])->assertUnauthorized();
        $this->getJson("/api/v1/projects/{$project->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/projects/{$project->id}", [])->assertUnauthorized();
        $this->postJson("/api/v1/projects/{$project->id}/archive")->assertUnauthorized();
        $this->postJson("/api/v1/projects/{$project->id}/restore")->assertUnauthorized();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertUnauthorized();
    }

    public function test_user_without_allowed_role_gets_forbidden(): void
    {
        $category = $this->createCategory();
        $project = Project::factory()->create(['category_id' => $category->id]);
        $this->actAsUserWithPermissions([]);

        $payload = [
            'name' => 'Forbidden Project',
            'category_id' => $category->id,
            'fund_type' => 'fixed',
            'status' => 'active',
            'operational_deduction_type' => 'relative',
            'administrative_exempt' => false,
        ];

        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->postJson('/api/v1/projects', $payload)->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->patchJson("/api/v1/projects/{$project->id}", $payload)->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->id}/restore")->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_roles_gate_matching_endpoints(): void
    {
        $category = $this->createCategory();
        $project = Project::factory()->create(['category_id' => $category->id]);

        $payload = [
            'name' => 'Authorized Project',
            'category_id' => $category->id,
            'fund_type' => 'fixed',
            'status' => 'active',
            'operational_deduction_type' => 'relative',
            'administrative_exempt' => false,
        ];

        $this->actAsFinanceUser();
        $this->getJson('/api/v1/projects')->assertOk();
        $this->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->postJson('/api/v1/projects', $payload)->assertForbidden();

        $this->actAsInventoryUser();
        $this->getJson('/api/v1/projects')->assertOk();
        $this->postJson('/api/v1/projects', $payload)->assertForbidden();

        $this->actAsSuperAdmin();
        $this->postJson('/api/v1/projects', $payload)->assertCreated();
        $this->patchJson("/api/v1/projects/{$project->id}", array_merge($payload, ['name' => 'Updated Authorized Project']))->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/archive")->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/restore")->assertOk();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertOk();
    }
}
