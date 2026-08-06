<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;
use Modules\Settings\Actions\UpsertMonthlyEmployeeSettingsAction;
use Modules\Settings\Models\SystemGeneralSetting;

class ProjectFinancialSettingsTest extends ProjectFeatureTestCase
{
    private function seedFinancialSettings(float $adminFee = 12): void
    {
        SystemGeneralSetting::singleton()->update([
            'admin_fee_percentage' => $adminFee,
        ]);
    }

    public function test_can_show_and_update_project_financial_settings(): void
    {
        $this->seedFinancialSettings(12);
        $this->actAsSuperAdmin();

        app(UpsertMonthlyEmployeeSettingsAction::class)->execute(
            (int) now()->month,
            (int) now()->year,
            [
                'fixed_workers' => 500,
                'media_staff' => 0,
                'administrative_staff' => 0,
                'variable_workers' => 0,
                'speakers' => 0,
                'cooks' => 0,
            ]
        );

        $this->getJson('/api/v1/projects/financial-settings')
            ->assertOk()
            ->assertJsonPath('data.admin_fee_percentage', 12)
            ->assertJsonPath('data.total_operational_deduction', 500);

        $this->patchJson('/api/v1/projects/financial-settings', [
            'admin_fee_percentage' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('data.admin_fee_percentage', 15)
            ->assertJsonPath('data.total_operational_deduction', 500);

        $this->assertDatabaseHas('system_general_settings', [
            'admin_fee_percentage' => 15,
        ]);
    }

    public function test_rejects_total_operational_deduction_on_financial_settings_patch(): void
    {
        $this->seedFinancialSettings();
        $this->actAsSuperAdmin();

        $this->patchJson('/api/v1/projects/financial-settings', [
            'total_operational_deduction' => 2000,
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_fee_percentage']);
    }

    public function test_new_projects_and_calculations_use_financial_settings(): void
    {
        $this->seedFinancialSettings(12);
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
        ])->assertOk();

        app(UpsertMonthlyEmployeeSettingsAction::class)->execute(
            (int) now()->month,
            (int) now()->year,
            [
                'fixed_workers' => 2000,
                'media_staff' => 0,
                'administrative_staff' => 0,
                'variable_workers' => 0,
                'speakers' => 0,
                'cooks' => 0,
            ]
        );

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

        $admin = app(AdministrativeDeductionService::class);
        $this->assertSame(120.0, $admin->calculate($projectAModel, 1000));
        $this->assertSame(150.0, $admin->calculate($projectBModel, 1000, now()->addDay()));

        $operational = app(OperationalDeductionService::class);
        // Mid-day monthly employee pool change applies from the next calendar day only.
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
