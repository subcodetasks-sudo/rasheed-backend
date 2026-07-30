<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Events\ProjectUpdated;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\UpdateProjectWorkflow;
use Modules\User\app\Models\User;
use Tests\TestCase;

class UpdateProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_project_and_dispatches_event(): void
    {
        Event::fake([ProjectUpdated::class]);

        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->fixedFund()->fixedDeduction(95)->create([
            'name' => 'Legacy Project',
            'category_id' => $category->id,
            'status' => ProjectStatus::Active,
        ]);

        $workflow = app(UpdateProjectWorkflow::class);

        $updated = $workflow->handle($project, ProjectData::fromArray([
            'name' => 'Updated Project',
            'category_id' => $otherCategory->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ]));

        $this->assertSame('Updated Project', $updated->name);
        $this->assertSame($otherCategory->id, $updated->category_id);
        $this->assertSame(FundType::Variable, $updated->fund_type);
        $this->assertSame(ProjectStatus::Stopped, $updated->status);
        $this->assertSame(OperationalDeductionType::Exempt, $updated->operational_deduction_type);
        $this->assertNull($updated->operational_fixed_amount);
        $this->assertTrue($updated->administrative_exempt);
        $this->assertSame($user->uuid, $updated->updated_by);

        Event::assertDispatched(ProjectUpdated::class);
    }

    public function test_archived_status_sets_archived_at_and_non_archived_status_clears_it(): void
    {
        $category = Category::factory()->create();
        $project = Project::factory()->create([
            'category_id' => $category->id,
            'status' => ProjectStatus::Active,
            'archived_at' => null,
        ]);

        $workflow = app(UpdateProjectWorkflow::class);

        $archived = $workflow->handle($project, ProjectData::fromArray([
            'name' => $project->name,
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Archived->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]));

        $this->assertNotNull($archived->archived_at);

        $restored = $workflow->handle($archived, ProjectData::fromArray([
            'name' => $archived->name,
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ]));

        $this->assertNull($restored->archived_at);
    }
}
