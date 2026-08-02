<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Settings\app\Models\Setting;
use Modules\Settings\Services\SettingService;

class AdministrativeFeePercentageSnapshotTest extends ProjectFeatureTestCase
{
    private function seedAdminFeeSetting(float|string $value): void
    {
        Setting::updateOrCreate(
            ['key' => 'admin_fee_percentage'],
            ['value' => $value, 'type' => 'decimal', 'is_public' => true]
        );

        app(SettingService::class)->update('admin_fee_percentage', $value, 'decimal', true);
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

    public function test_editing_project_does_not_overwrite_stored_admin_fee_percentage(): void
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
}
