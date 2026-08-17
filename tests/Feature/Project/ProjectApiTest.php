<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notifications\Models\Notification;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::findOrCreate('super-admin', 'web');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        Sanctum::actingAs($this->user);

        $this->category = Category::factory()->create(['name' => 'Relief']);
    }

    public function test_can_create_project(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Food Aid',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 154,
            'administrative_exempt' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Food Aid')
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.operational_deduction_type', 'fixed')
            ->assertJsonPath('data.operational_fixed_amount', '154.00');

        $this->assertDatabaseHas('projects', ['name' => 'Food Aid']);
    }

    public function test_can_create_project_with_relative_deduction(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Relative Aid',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.operational_deduction_type', 'relative')
            ->assertJsonMissingPath('data.operational_fixed_amount');

        $this->assertDatabaseHas('projects', [
            'name' => 'Relative Aid',
            'operational_deduction_type' => 'relative',
            'operational_fixed_amount' => null,
        ]);
    }

    public function test_can_create_project_with_exempt_deduction(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Exempt Aid',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.operational_deduction_type', 'exempt')
            ->assertJsonPath('data.administrative_exempt', true);

        $this->assertDatabaseHas('projects', [
            'name' => 'Exempt Aid',
            'operational_deduction_type' => 'exempt',
            'operational_fixed_amount' => null,
        ]);
    }

    public function test_create_requires_fixed_amount_when_fixed_deduction(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Missing Amount',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'administrative_exempt' => false,
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_create_rejects_fixed_amount_when_not_fixed_deduction(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Unexpected Amount',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'operational_fixed_amount' => 999,
            'administrative_exempt' => false,
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseMissing('projects', ['name' => 'Unexpected Amount']);
    }

    public function test_create_rejects_invalid_enum_values(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Invalid Enum',
            'category_id' => $this->category->id,
            'fund_type' => 'not-a-real-type',
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['fund_type']);
    }

    public function test_create_requires_existing_category(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Ghost Category',
            'category_id' => 999999,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_create_project_requires_authorization(): void
    {
        $unauthorizedUser = User::factory()->create();
        Sanctum::actingAs($unauthorizedUser);

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Unauthorized Project',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('projects', ['name' => 'Unauthorized Project']);
    }

    public function test_can_list_projects_by_tab(): void
    {
        Project::factory()->fixedFund()->create(['name' => 'Fixed One', 'category_id' => $this->category->id]);
        Project::factory()->variableFund()->create(['name' => 'Variable One', 'category_id' => $this->category->id]);
        Project::factory()->archived()->create(['name' => 'Old Project', 'category_id' => $this->category->id]);

        $this->getJson('/api/v1/projects?tab=fixed')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/projects?tab=variable')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/projects?tab=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_and_search_projects(): void
    {
        Project::factory()->create([
            'name' => 'Alpha Care',
            'fund_type' => FundType::Fixed,
            'category_id' => $this->category->id,
        ]);
        Project::factory()->create([
            'name' => 'Beta Cash',
            'fund_type' => FundType::Variable,
            'category_id' => $this->category->id,
        ]);

        $this->getJson('/api/v1/projects?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Care');

        $this->getJson('/api/v1/projects?fund_type=variable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_projects_by_category(): void
    {
        $otherCategory = Category::factory()->create(['name' => 'Other']);

        Project::factory()->create([
            'name' => 'In Relief Category',
            'category_id' => $this->category->id,
        ]);
        Project::factory()->create([
            'name' => 'In Other Category',
            'category_id' => $otherCategory->id,
        ]);

        $this->getJson('/api/v1/projects?filter[category_id]='.$this->category->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'In Relief Category');
    }

    public function test_can_show_update_archive_restore_and_delete_project(): void
    {
        $project = Project::factory()->create([
            'name' => 'Lifecycle',
            'category_id' => $this->category->id,
        ]);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Lifecycle');

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => 'Lifecycle Updated',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Lifecycle Updated')
            ->assertJsonPath('data.status', 'stopped')
            ->assertJsonPath('data.administrative_exempt', true);

        $this->postJson("/api/v1/projects/{$project->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertNotNull($project->fresh()->archived_at);

        $this->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNull($project->fresh()->archived_at);

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_delete_project_stores_notification_without_project_fk(): void
    {
        $project = Project::factory()->create([
            'name' => 'يثيث',
            'category_id' => $this->category->id,
        ]);
        $projectId = $project->id;

        $this->deleteJson("/api/v1/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('projects', ['id' => $projectId]);

        $notification = Notification::query()
            ->where('meta->action', 'deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertNull($notification->project_id);
        $this->assertSame($projectId, (int) $notification->meta['project_id']);
    }

    public function test_project_name_must_be_unique(): void
    {
        Project::factory()->create([
            'name' => 'Unique Name',
            'category_id' => $this->category->id,
        ]);

        $this->postJson('/api/v1/projects', [
            'name' => 'Unique Name',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
        ])->assertStatus(422);
    }
}
