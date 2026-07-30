<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;

class CalculateProjectDeductionApiTest extends ProjectFeatureTestCase
{
    public function test_calculate_single_project_deduction_requires_authentication_and_view_permission(): void
    {
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/calculate-deduction", [])
            ->assertUnauthorized();

        $this->actAsUserWithPermissions([]);

        $this->postJson("/api/v1/projects/{$project->id}/calculate-deduction", [
            'income' => 1000,
        ])->assertForbidden();
    }

    public function test_calculate_single_project_deduction_supports_relative_fixed_and_exempt_modes(): void
    {
        $this->actAsFinanceUser();

        $relative = Project::factory()->create([
            'name' => 'Relative Project',
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => false,
        ]);

        $fixed = Project::factory()->fixedDeduction(75)->create([
            'name' => 'Fixed Project',
            'administrative_exempt' => true,
        ]);

        $exempt = Project::factory()->exempt()->create([
            'name' => 'Exempt Project',
            'administrative_exempt' => false,
        ]);

        $this->postJson("/api/v1/projects/{$relative->id}/calculate-deduction", [
            'income' => 500,
            'relative_incomes' => [
                $relative->id => 500,
                999 => 500,
            ],
        ])->assertOk()
            ->assertJsonPath('data.operational_deduction', 540.5)
            ->assertJsonPath('data.administrative_deduction', 60)
            ->assertJsonPath('data.operational_deduction_type', OperationalDeductionType::Relative->value);

        $this->postJson("/api/v1/projects/{$fixed->id}/calculate-deduction", [
            'income' => 500,
        ])->assertOk()
            ->assertJsonPath('data.operational_deduction', 75)
            ->assertJsonPath('data.administrative_deduction', 0)
            ->assertJsonPath('data.administrative_exempt', true);

        $this->postJson("/api/v1/projects/{$exempt->id}/calculate-deduction", [
            'income' => 500,
        ])->assertOk()
            ->assertJsonPath('data.operational_deduction', 0)
            ->assertJsonPath('data.administrative_deduction', 60)
            ->assertJsonPath('data.operational_deduction_type', OperationalDeductionType::Exempt->value);
    }

    public function test_calculate_single_project_deduction_validates_payload(): void
    {
        $this->actAsFinanceUser();
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/calculate-deduction", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['income']);

        $this->postJson("/api/v1/projects/{$project->id}/calculate-deduction", [
            'income' => -1,
            'relative_incomes' => [-1],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['income', 'relative_incomes.0']);
    }
}
