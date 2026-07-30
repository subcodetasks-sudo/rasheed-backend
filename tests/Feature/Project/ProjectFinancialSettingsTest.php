<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;
use Modules\Settings\Services\SettingService;

class ProjectFinancialSettingsTest extends ProjectFeatureTestCase
{
    private function seedFinancialSettings(float $adminFee = 12, float $totalOperational = 1081): void
    {
        $settings = app(SettingService::class);
        $settings->update('admin_fee_percentage', $adminFee, 'decimal', true);
        $settings->update('total_operational_deduction', $totalOperational, 'decimal', true);
    }

    public function test_can_show_and_update_project_financial_settings(): void
    {
        $this->seedFinancialSettings(12, 1081);
        $this->actAsSuperAdmin();

        $this->getJson('/api/v1/projects/financial-settings')
            ->assertOk()
            ->assertJsonPath('data.admin_fee_percentage', 12)
            ->assertJsonPath('data.total_operational_deduction', 1081);

        $this->patchJson('/api/v1/projects/financial-settings', [
            'admin_fee_percentage' => 15,
            'total_operational_deduction' => 2000,
        ])
            ->assertOk()
            ->assertJsonPath('data.admin_fee_percentage', 15)
            ->assertJsonPath('data.total_operational_deduction', 2000);

        $this->assertDatabaseHas('settings', [
            'key' => 'admin_fee_percentage',
            'value' => '15',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'total_operational_deduction',
            'value' => '2000',
        ]);
    }

    public function test_new_projects_and_calculations_use_financial_settings(): void
    {
        $this->seedFinancialSettings(12, 1081);
        $this->actAsSuperAdmin();

        $category = $this->createCategory();

        $projectA = $this->postJson('/api/v1/projects', [
            'name' => 'Project A',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated()
            ->json('data');

        $this->assertSame('12.00', $projectA['administrative_fee_percentage']);

        $this->patchJson('/api/v1/projects/financial-settings', [
            'admin_fee_percentage' => 15,
            'total_operational_deduction' => 2000,
        ])->assertOk();

        $projectB = $this->postJson('/api/v1/projects', [
            'name' => 'Project B',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated()
            ->json('data');

        $this->assertSame('15.00', $projectB['administrative_fee_percentage']);

        $projectAModel = Project::query()->findOrFail($projectA['id']);
        $projectBModel = Project::query()->findOrFail($projectB['id']);

        $admin = new AdministrativeDeductionService;
        $this->assertSame(120.0, $admin->calculate($projectAModel, 1000));
        $this->assertSame(150.0, $admin->calculate($projectBModel, 1000));

        $operational = app(OperationalDeductionService::class);
        // Mid-day setting change applies from the next calendar day only.
        $this->assertSame(1081.0, $operational->calculate($projectBModel, 1000, 1000));
        $this->assertSame(2000.0, $operational->calculate($projectBModel, 1000, 1000, null, now()->addDay()));
    }

    public function test_financial_settings_update_requires_super_admin(): void
    {
        $this->seedFinancialSettings();
        $this->actAsFinanceUser();

        $this->patchJson('/api/v1/projects/financial-settings', [
            'admin_fee_percentage' => 15,
        ])->assertForbidden();
    }
}
