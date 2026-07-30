<?php

namespace Tests\Feature\Project;

use Illuminate\Support\Facades\Event;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Events\ProjectUpdated;
use Modules\Project\Models\Project;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;
use Modules\Settings\Services\SettingService;

class DeductionConfigurationTest extends ProjectFeatureTestCase
{
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Deduction Config Project',
            'category_id' => $this->createCategory()->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ], $overrides);
    }

    public function test_relative_fixed_and_exempt_projects_store_configuration_only(): void
    {
        $this->actAsSuperAdmin(['create-projects']);
        app(SettingService::class)->update('admin_fee_percentage', 12, 'decimal', true);

        $relative = $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Relative Project',
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]))->assertCreated();

        $relative->assertJsonPath('data.operational_deduction_type', 'relative')
            ->assertJsonMissingPath('data.operational_fixed_amount')
            ->assertJsonPath('data.administrative_exempt', false);

        $this->assertDatabaseHas('projects', [
            'name' => 'Relative Project',
            'operational_deduction_type' => 'relative',
            'operational_fixed_amount' => null,
            'administrative_exempt' => 0,
        ]);

        $fixed = $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Fixed Project',
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 154,
            'administrative_exempt' => true,
        ]))->assertCreated();

        $fixed->assertJsonPath('data.operational_deduction_type', 'fixed')
            ->assertJsonPath('data.operational_fixed_amount', '154.00')
            ->assertJsonPath('data.administrative_exempt', true);

        $this->assertDatabaseHas('projects', [
            'name' => 'Fixed Project',
            'operational_deduction_type' => 'fixed',
            'operational_fixed_amount' => '154.00',
            'administrative_exempt' => 1,
        ]);

        $exempt = $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Exempt Project',
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => false,
        ]))->assertCreated();

        $exempt->assertJsonPath('data.operational_deduction_type', 'exempt')
            ->assertJsonMissingPath('data.operational_fixed_amount');

        $this->assertDatabaseHas('projects', [
            'name' => 'Exempt Project',
            'operational_deduction_type' => 'exempt',
            'operational_fixed_amount' => null,
        ]);
    }

    public function test_all_operational_and_administrative_combinations_are_valid(): void
    {
        $this->actAsSuperAdmin(['create-projects']);

        $combinations = [
            ['relative', null, true],
            ['relative', null, false],
            ['fixed', 154, true],
            ['fixed', 154, false],
            ['exempt', null, true],
            ['exempt', null, false],
        ];

        foreach ($combinations as $index => [$type, $amount, $adminExempt]) {
            $payload = $this->basePayload([
                'name' => "Combo {$index}",
                'operational_deduction_type' => $type,
                'administrative_exempt' => $adminExempt,
            ]);

            if ($amount !== null) {
                $payload['operational_fixed_amount'] = $amount;
            }

            $this->postJson('/api/v1/projects', $payload)
                ->assertCreated()
                ->assertJsonPath('data.operational_deduction_type', $type)
                ->assertJsonPath('data.administrative_exempt', $adminExempt);

            $this->assertDatabaseHas('projects', [
                'name' => "Combo {$index}",
                'operational_deduction_type' => $type,
                'administrative_exempt' => $adminExempt ? 1 : 0,
                'operational_fixed_amount' => $amount === null ? null : number_format($amount, 2, '.', ''),
            ]);
        }
    }

    public function test_fixed_amount_rules_are_enforced_for_each_operational_type(): void
    {
        $this->actAsSuperAdmin(['create-projects']);
        $categoryId = $this->createCategory()->id;

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Fixed Without Amount',
            'category_id' => $categoryId,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Fixed Zero',
            'category_id' => $categoryId,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 0,
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Fixed Negative',
            'category_id' => $categoryId,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => -10,
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Fixed Non Numeric',
            'category_id' => $categoryId,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 'abc',
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Relative With Amount',
            'category_id' => $categoryId,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'operational_fixed_amount' => 25,
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Exempt With Amount',
            'category_id' => $categoryId,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'operational_fixed_amount' => 25,
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_fixed_amount']);
    }

    public function test_missing_and_invalid_operational_deduction_types_are_rejected(): void
    {
        $this->actAsSuperAdmin(['create-projects']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Missing Type',
            'operational_deduction_type' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_deduction_type']);

        $payload = $this->basePayload(['name' => 'Missing Key']);
        unset($payload['operational_deduction_type']);

        $this->postJson('/api/v1/projects', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operational_deduction_type']);

        $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Invalid Type',
            'operational_deduction_type' => 'both-relative-and-fixed',
        ]))->assertStatus(422)->assertJsonValidationErrors(['operational_deduction_type']);
    }

    public function test_edit_updates_future_configuration_without_invoking_financial_calculations(): void
    {
        $this->actAsSuperAdmin(['create-projects', 'edit-projects']);

        $this->mock(OperationalDeductionService::class, function ($mock) {
            $mock->shouldNotReceive('calculate');
            $mock->shouldNotReceive('distribute');
        });

        $this->mock(AdministrativeDeductionService::class, function ($mock) {
            $mock->shouldNotReceive('calculate');
        });

        Event::fake([ProjectCreated::class, ProjectUpdated::class]);

        $create = $this->postJson('/api/v1/projects', $this->basePayload([
            'name' => 'Editable Deduction Project',
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]))->assertCreated();

        $projectId = $create->json('data.id');

        $this->patchJson("/api/v1/projects/{$projectId}", $this->basePayload([
            'name' => 'Editable Deduction Project',
            'category_id' => Project::findOrFail($projectId)->category_id,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 200,
            'administrative_exempt' => true,
        ]))->assertOk()
            ->assertJsonPath('data.operational_deduction_type', 'fixed')
            ->assertJsonPath('data.operational_fixed_amount', '200.00')
            ->assertJsonPath('data.administrative_exempt', true);

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'operational_deduction_type' => 'fixed',
            'operational_fixed_amount' => '200.00',
            'administrative_exempt' => 1,
        ]);

        Event::assertDispatched(ProjectCreated::class);
        Event::assertDispatched(ProjectUpdated::class);
    }

    public function test_operational_services_respect_relative_fixed_and_exempt_participation_rules(): void
    {
        $relative = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $fixed = Project::factory()->fixedDeduction(154)->create([
            'status' => ProjectStatus::Active,
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 12,
        ]);

        $exempt = Project::factory()->exempt()->create([
            'status' => ProjectStatus::Active,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $operational = app(OperationalDeductionService::class);
        $administrative = new AdministrativeDeductionService;

        $distributed = $operational->distribute(collect([$relative, $fixed, $exempt]), [
            $relative->id => 1000,
            $fixed->id => 1000,
            $exempt->id => 1000,
        ]);

        $this->assertSame(1081.0, $distributed[$relative->id]);
        $this->assertSame(154.0, $distributed[$fixed->id]);
        $this->assertArrayNotHasKey($exempt->id, $distributed);

        $this->assertSame(120.0, $administrative->calculate($relative, 1000));
        $this->assertSame(0.0, $administrative->calculate($fixed, 1000));
        $this->assertSame(120.0, $administrative->calculate($exempt, 1000));
    }
}
