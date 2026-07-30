<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Models\Category;
use Modules\Project\Workflows\Project\CreateProjectWorkflow;
use Modules\User\app\Models\User;
use Tests\TestCase;

class CreateProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_project_and_clears_fixed_amount_for_relative(): void
    {
        Event::fake([ProjectCreated::class]);

        $category = Category::factory()->create();

        $workflow = app(CreateProjectWorkflow::class);

        $project = $workflow->handle(ProjectData::fromArray([
            'name' => 'Relative Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'operational_fixed_amount' => 999,
            'administrative_exempt' => false,
        ]));

        $this->assertSame('Relative Project', $project->name);
        $this->assertNull($project->operational_fixed_amount);
        $this->assertTrue($project->hasRelativeOperationalDeduction());

        Event::assertDispatched(ProjectCreated::class);
    }

    public function test_keeps_fixed_amount_for_fixed_deduction(): void
    {
        $category = Category::factory()->create();
        $workflow = app(CreateProjectWorkflow::class);

        $project = $workflow->handle(ProjectData::fromArray([
            'name' => 'Fixed Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 154,
            'administrative_exempt' => true,
        ]));

        $this->assertTrue($project->hasFixedOperationalDeduction());
        $this->assertEquals(154, (float) $project->operational_fixed_amount);
        $this->assertTrue($project->administrative_exempt);
    }

    public function test_clears_fixed_amount_for_exempt_deduction(): void
    {
        $category = Category::factory()->create();
        $workflow = app(CreateProjectWorkflow::class);

        $project = $workflow->handle(ProjectData::fromArray([
            'name' => 'Exempt Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'operational_fixed_amount' => 500,
            'administrative_exempt' => false,
        ]));

        $this->assertNull($project->operational_fixed_amount);
        $this->assertTrue($project->isOperationalExempt());
    }

    public function test_sets_created_by_and_updated_by_from_authenticated_user(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $workflow = app(CreateProjectWorkflow::class);

        $project = $workflow->handle(ProjectData::fromArray([
            'name' => 'Audited Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]));

        $this->assertSame($user->uuid, $project->created_by);
        $this->assertSame($user->uuid, $project->updated_by);
    }
}
