<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Settings\Models\SystemGeneralSetting;

class AdministrativeFeePercentageSnapshotTest extends ProjectFeatureTestCase
{
    private function seedAdminFeeSetting(float|string $value): void
    {
        SystemGeneralSetting::singleton()->update([
            'admin_fee_percentage' => $value,
        ]);
    }

    public function test_new_project_stores_current_admin_fee_percentage_from_settings(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin(['create-projects']);
        $category = $this->createCategory();

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Project A',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.administrative_fee_percentage', '12.00');

        $this->assertDatabaseHas('projects', [
            'name' => 'Project A',
            'administrative_fee_percentage' => '12.00',
        ]);
    }

    public function test_changing_setting_only_affects_future_projects(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin(['create-projects', 'edit-projects', 'view-projects']);
        $category = $this->createCategory();

        $this->postJson('/api/v1/projects', [
            'name' => 'Project A',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $this->seedAdminFeeSetting(15);

        $this->postJson('/api/v1/projects', [
            'name' => 'Project B',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $projectA = Project::query()->where('name', 'Project A')->firstOrFail();
        $projectB = Project::query()->where('name', 'Project B')->firstOrFail();

        $this->assertEquals(12.0, (float) $projectA->administrative_fee_percentage);
        $this->assertEquals(15.0, (float) $projectB->administrative_fee_percentage);
    }

    public function test_editing_project_without_supplying_percentage_does_not_overwrite_stored_value(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin(['create-projects', 'edit-projects']);
        $category = $this->createCategory();

        $create = $this->postJson('/api/v1/projects', [
            'name' => 'Historical Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $projectId = $create->json('data.id');

        $this->seedAdminFeeSetting(20);

        $this->patchJson("/api/v1/projects/{$projectId}", [
            'name' => 'Historical Project Updated',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Historical Project Updated')
            ->assertJsonPath('data.administrative_fee_percentage', '12.00');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'name' => 'Historical Project Updated',
            'administrative_fee_percentage' => '12.00',
        ]);
    }

    public function test_client_can_override_admin_fee_percentage_on_create(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin();
        $category = $this->createCategory();

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Project With Override',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 18,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.administrative_fee_percentage', '18.00');

        $this->assertDatabaseHas('projects', [
            'name' => 'Project With Override',
            'administrative_fee_percentage' => '18.00',
        ]);
    }

    public function test_user_can_edit_a_projects_percentage_and_it_becomes_effective_tomorrow(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin();
        $category = $this->createCategory();

        $create = $this->postJson('/api/v1/projects', [
            'name' => 'Editable Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $projectId = $create->json('data.id');

        $this->patchJson("/api/v1/projects/{$projectId}", [
            'name' => 'Editable Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('data.administrative_fee_percentage', '15.00');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'administrative_fee_percentage' => '15.00',
        ]);
    }

    public function test_editing_one_project_does_not_affect_another(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin();
        $category = $this->createCategory();

        $a = $this->postJson('/api/v1/projects', [
            'name' => 'Project A',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/projects', [
            'name' => 'Project B',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $this->patchJson("/api/v1/projects/{$a}", [
            'name' => 'Project A',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 25,
        ])->assertOk();

        $this->assertDatabaseHas('projects', ['name' => 'Project A', 'administrative_fee_percentage' => '25.00']);
        $this->assertDatabaseHas('projects', ['name' => 'Project B', 'administrative_fee_percentage' => '12.00']);
    }

    public function test_unauthorized_user_cannot_update_admin_fee_percentage(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSuperAdmin();
        $category = $this->createCategory();

        $projectId = $this->postJson('/api/v1/projects', [
            'name' => 'Protected Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated()->json('data.id');

        $this->actAsFinanceUser();

        $this->patchJson("/api/v1/projects/{$projectId}", [
            'name' => 'Protected Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 99,
        ])->assertForbidden();

        $this->assertDatabaseHas('projects', ['name' => 'Protected Project', 'administrative_fee_percentage' => '12.00']);
    }
}
