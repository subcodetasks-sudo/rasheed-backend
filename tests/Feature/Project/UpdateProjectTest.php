<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;

class UpdateProjectTest extends ProjectFeatureTestCase
{
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsSuperAdmin(['edit-projects']);
        $this->category = $this->createCategory(['name' => 'Original']);
    }

    public function test_can_update_basic_project_information_and_resource_shape(): void
    {
        $newCategory = $this->createCategory(['name' => 'Updated Category']);
        $project = Project::factory()->fixedFund()->fixedDeduction(150)->create([
            'name' => 'Legacy Project',
            'category_id' => $this->category->id,
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
        ]);

        $response = $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => 'Updated Project',
            'category_id' => $newCategory->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', 'Updated Project')
            ->assertJsonPath('data.category.id', $newCategory->id)
            ->assertJsonPath('data.fund_type', FundType::Variable->value)
            ->assertJsonPath('data.status', ProjectStatus::Stopped->value)
            ->assertJsonPath('data.operational_deduction_type', OperationalDeductionType::Exempt->value)
            ->assertJsonMissingPath('data.operational_fixed_amount')
            ->assertJsonPath('data.administrative_exempt', true);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated Project',
            'category_id' => $newCategory->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'operational_fixed_amount' => null,
            'administrative_exempt' => 1,
        ]);
    }

    public function test_update_keeps_same_name_unique_rule_and_validates_new_duplicates(): void
    {
        $project = Project::factory()->create([
            'name' => 'Same Name',
            'category_id' => $this->category->id,
        ]);

        Project::factory()->create([
            'name' => 'Taken Name',
            'category_id' => $this->category->id,
        ]);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => 'Same Name',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertOk();

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => 'Taken Name',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_applies_same_validation_rules_as_create(): void
    {
        $project = Project::factory()->create([
            'category_id' => $this->category->id,
        ]);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => '',
            'category_id' => 999999,
            'fund_type' => 'bad-fund',
            'status' => 'bad-status',
            'operational_deduction_type' => 'bad-deduction',
            'administrative_exempt' => 'bad-bool',
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'category_id',
                'fund_type',
                'status',
                'operational_deduction_type',
                'administrative_exempt',
            ]);
    }

    public function test_update_requires_fixed_amount_for_fixed_deduction_and_clears_it_for_other_modes(): void
    {
        $project = Project::factory()->fixedFund()->fixedDeduction(90)->create([
            'category_id' => $this->category->id,
        ]);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => $project->name,
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => $project->name,
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertOk()
            ->assertJsonMissingPath('data.operational_fixed_amount');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'operational_fixed_amount' => null,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
        ]);
    }

    public function test_update_can_mark_project_archived_or_reactivate_it_via_status_field(): void
    {
        $project = Project::factory()->create([
            'category_id' => $this->category->id,
            'status' => ProjectStatus::Active,
            'archived_at' => null,
        ]);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => $project->name,
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Archived->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::Archived->value);

        $this->assertNotNull($project->fresh()->archived_at);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => $project->fresh()->name,
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::Active->value)
            ->assertJsonPath('data.archived_at', null);

        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_updating_one_project_does_not_affect_listing_or_other_projects(): void
    {
        $project = Project::factory()->create([
            'name' => 'Alpha Project',
            'category_id' => $this->category->id,
        ]);

        $otherProject = Project::factory()->create([
            'name' => 'Beta Project',
            'category_id' => $this->category->id,
        ]);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'name' => 'Gamma Project',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => true,
        ])->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $otherProject->id, 'name' => 'Beta Project']);

        $this->actAsUserWithPermissions(['view-projects']);

        $this->getJson('/api/v1/projects?search=Gamma')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);
    }
}
