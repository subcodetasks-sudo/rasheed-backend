<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;

class CalculateDeductionsApiTest extends ProjectFeatureTestCase
{
    public function test_calculate_deductions_requires_authentication_and_view_permission(): void
    {
        $this->postJson('/api/v1/projects/calculate-deductions', [])->assertUnauthorized();

        $this->actAsUserWithPermissions([]);

        $this->postJson('/api/v1/projects/calculate-deductions', [
            'incomes' => [1 => 1000],
        ])->assertForbidden();
    }

    public function test_calculate_deductions_returns_expected_breakdown_for_active_projects(): void
    {
        $this->actAsFinanceUser();

        $relative = Project::factory()->create([
            'name' => 'Relative Project',
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => false,
        ]);

        $fixed = Project::factory()->fixedDeduction(154)->create([
            'name' => 'Fixed Project',
            'status' => ProjectStatus::Active,
            'administrative_exempt' => true,
        ]);

        $exempt = Project::factory()->exempt()->create([
            'name' => 'Exempt Project',
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
        ]);

        $response = $this->postJson('/api/v1/projects/calculate-deductions', [
            'incomes' => [
                $relative->id => 1000,
                $fixed->id => 5000,
                $exempt->id => 900,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        $response
            ->assertJsonPath('data.0.project_id', $relative->id)
            ->assertJsonPath('data.0.project_name', 'Relative Project')
            ->assertJsonPath('data.0.income', 1000)
            ->assertJsonPath('data.0.operational_deduction_type', OperationalDeductionType::Relative->value)
            ->assertJsonPath('data.0.operational_deduction', 1081)
            ->assertJsonPath('data.0.administrative_deduction', 120)
            ->assertJsonPath('data.0.administrative_exempt', false)
            ->assertJsonPath('data.1.project_id', $fixed->id)
            ->assertJsonPath('data.1.project_name', 'Fixed Project')
            ->assertJsonPath('data.1.income', 5000)
            ->assertJsonPath('data.1.operational_deduction_type', OperationalDeductionType::Fixed->value)
            ->assertJsonPath('data.1.operational_deduction', 154)
            ->assertJsonPath('data.1.administrative_deduction', 0)
            ->assertJsonPath('data.1.administrative_exempt', true)
            ->assertJsonPath('data.2.project_id', $exempt->id)
            ->assertJsonPath('data.2.project_name', 'Exempt Project')
            ->assertJsonPath('data.2.income', 900)
            ->assertJsonPath('data.2.operational_deduction_type', OperationalDeductionType::Exempt->value)
            ->assertJsonPath('data.2.operational_deduction', 0)
            ->assertJsonPath('data.2.administrative_deduction', 108)
            ->assertJsonPath('data.2.administrative_exempt', false);
    }

    public function test_calculate_deductions_validates_request_shape(): void
    {
        $this->actAsFinanceUser();

        $this->postJson('/api/v1/projects/calculate-deductions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['incomes']);

        $this->postJson('/api/v1/projects/calculate-deductions', [
            'incomes' => ['bad' => -10],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['incomes.bad']);
    }
}
