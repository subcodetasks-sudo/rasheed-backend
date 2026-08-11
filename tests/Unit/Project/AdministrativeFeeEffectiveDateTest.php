<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Project\Actions\Project\ResolveEffectiveAdminFeePercentageAction;
use Modules\Project\Actions\Project\ScheduleAdminFeePercentageChangeAction;
use Modules\Project\Models\AdministrativeFeeRate;
use Modules\Project\Models\Project;
use Modules\Settings\Actions\UpdateSystemGeneralSettingsAction;
use Tests\TestCase;

class AdministrativeFeeEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:45:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_change_keeps_today_and_applies_tomorrow(): void
    {
        $project = Project::factory()->create(['administrative_fee_percentage' => 12]);
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $schedule = app(ScheduleAdminFeePercentageChangeAction::class);

        $this->assertSame(12.0, $resolve->execute($project, '2026-07-30'));

        $schedule->execute($project, 15);

        $this->assertSame(12.0, $resolve->execute($project, '2026-07-30'));
        $this->assertSame(15.0, $resolve->execute($project, '2026-07-31'));

        $this->assertTrue(
            AdministrativeFeeRate::query()
                ->where('project_id', $project->id)
                ->whereDate('effective_from', '2026-07-31')
                ->where('percentage', 15)
                ->exists()
        );
    }

    public function test_same_day_second_edit_only_updates_tomorrow_rate(): void
    {
        $project = Project::factory()->create(['administrative_fee_percentage' => 12]);
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $schedule = app(ScheduleAdminFeePercentageChangeAction::class);

        $schedule->execute($project, 15);
        $schedule->execute($project, 20);

        $this->assertSame(12.0, $resolve->execute($project, '2026-07-30'));
        $this->assertSame(20.0, $resolve->execute($project, '2026-07-31'));

        $this->assertSame(
            1,
            AdministrativeFeeRate::query()
                ->where('project_id', $project->id)
                ->whereDate('effective_from', '2026-07-31')
                ->count()
        );
    }

    public function test_change_pins_today_rate_so_a_rewritten_history_row_cannot_leak(): void
    {
        $project = Project::factory()->create(['administrative_fee_percentage' => 12]);
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $schedule = app(ScheduleAdminFeePercentageChangeAction::class);

        $schedule->execute($project, 15);

        AdministrativeFeeRate::query()
            ->where('project_id', $project->id)
            ->whereDate('effective_from', '<', '2026-07-30')
            ->update(['percentage' => 15]);

        $this->assertSame(12.0, $resolve->execute($project, '2026-07-30'));
        $this->assertSame(15.0, $resolve->execute($project, '2026-07-31'));
    }

    public function test_historical_date_unaffected_after_change(): void
    {
        $project = Project::factory()->create(['administrative_fee_percentage' => 12]);
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $schedule = app(ScheduleAdminFeePercentageChangeAction::class);

        $schedule->execute($project, 15);

        $this->assertSame(12.0, $resolve->execute($project, '2026-07-15'));
        $this->assertSame(12.0, $resolve->execute($project, '2026-07-29'));
    }

    public function test_changing_one_projects_percentage_does_not_affect_another(): void
    {
        $projectA = Project::factory()->create(['administrative_fee_percentage' => 12]);
        $projectB = Project::factory()->create(['administrative_fee_percentage' => 12]);
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $schedule = app(ScheduleAdminFeePercentageChangeAction::class);

        $schedule->execute($projectA, 15);

        $this->assertSame(12.0, $resolve->execute($projectA, '2026-07-30'));
        $this->assertSame(15.0, $resolve->execute($projectA, '2026-07-31'));
        $this->assertSame(12.0, $resolve->execute($projectB, '2026-07-30'));
        $this->assertSame(12.0, $resolve->execute($projectB, '2026-07-31'));
    }

    public function test_global_setting_change_does_not_write_project_rate_history(): void
    {
        $project = Project::factory()->create(['administrative_fee_percentage' => 12]);

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 20]);

        $this->assertSame(
            0,
            AdministrativeFeeRate::query()->where('project_id', $project->id)->count()
        );

        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $this->assertSame(12.0, $resolve->execute($project, '2026-07-31'));
    }
}
