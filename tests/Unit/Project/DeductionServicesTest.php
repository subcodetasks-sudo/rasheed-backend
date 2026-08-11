<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\AdministrativeFeeRate;
use Modules\Project\Models\OperationalDeductionRate;
use Modules\Project\Models\Project;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;
use Tests\TestCase;

class DeductionServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_relative_operational_deduction_formula(): void
    {
        $service = app(OperationalDeductionService::class);

        $a = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);
        $b = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);

        $deductions = $service->distribute(collect([$a, $b]), [
            $a->id => 1000,
            $b->id => 1000,
        ]);

        $this->assertEquals(540.5, $deductions[$a->id]);
        $this->assertEquals(540.5, $deductions[$b->id]);
    }

    public function test_fixed_and_exempt_operational_deductions(): void
    {
        $service = app(OperationalDeductionService::class);

        $fixed = Project::factory()->fixedDeduction(154)->create();
        $exempt = Project::factory()->exempt()->create();

        $this->assertEquals(154.0, $service->calculate($fixed, 5000, 10000));
        $this->assertEquals(0.0, $service->calculate($exempt, 5000, 10000));

        $distributed = $service->distribute(new Collection([$fixed, $exempt]), [
            $fixed->id => 5000,
            $exempt->id => 5000,
        ]);

        $this->assertArrayHasKey($fixed->id, $distributed);
        $this->assertArrayNotHasKey($exempt->id, $distributed);
    }

    public function test_administrative_deduction_uses_each_projects_own_percentage_unless_exempt(): void
    {
        $service = app(AdministrativeDeductionService::class);

        $subject = Project::factory()->create([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);
        $customStored = Project::factory()->create([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 15,
        ]);
        $exempt = Project::factory()->create([
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 15,
        ]);

        $this->assertEquals(120.0, $service->calculate($subject, 1000));
        $this->assertEquals(150.0, $service->calculate($customStored, 1000));
        $this->assertEquals(0.0, $service->calculate($exempt, 1000));
    }

    public function test_administrative_deduction_zero_percent_does_not_fall_back_to_default(): void
    {
        $service = app(AdministrativeDeductionService::class);

        $project = Project::factory()->create([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 0,
        ]);

        $this->assertEquals(0.0, $service->calculate($project, 1000));
    }

    public function test_administrative_deduction_uses_effective_percentage_for_date(): void
    {
        $project = Project::factory()->create([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        AdministrativeFeeRate::query()->updateOrCreate(
            ['project_id' => $project->id, 'effective_from' => '2000-01-01'],
            ['percentage' => 20]
        );

        $service = app(AdministrativeDeductionService::class);

        $this->assertEquals(200.0, $service->calculate($project, 1000, '2026-07-30'));
    }

    public function test_administrative_deduction_rate_history_does_not_leak_between_projects(): void
    {
        $projectA = Project::factory()->create([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);
        $projectB = Project::factory()->create([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        AdministrativeFeeRate::query()->updateOrCreate(
            ['project_id' => $projectA->id, 'effective_from' => '2000-01-01'],
            ['percentage' => 20]
        );

        $service = app(AdministrativeDeductionService::class);

        $this->assertEquals(200.0, $service->calculate($projectA, 1000, '2026-07-30'));
        $this->assertEquals(120.0, $service->calculate($projectB, 1000, '2026-07-30'));
    }

    public function test_relative_operational_deduction_uses_effective_pool_for_date(): void
    {
        OperationalDeductionRate::query()->updateOrCreate(
            ['effective_from' => '2000-01-01'],
            ['amount' => 2000]
        );

        $service = app(OperationalDeductionService::class);

        $project = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);

        $this->assertEquals(2000.0, $service->calculate($project, 1000, 1000));
    }
}
