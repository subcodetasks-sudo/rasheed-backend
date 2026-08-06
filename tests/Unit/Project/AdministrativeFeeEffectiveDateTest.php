<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Project\Actions\Project\ResolveEffectiveAdminFeePercentageAction;
use Modules\Project\Actions\Project\ScheduleAdminFeePercentageChangeAction;
use Modules\Project\Models\AdministrativeFeeRate;
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

    public function test_setting_change_keeps_today_and_applies_tomorrow(): void
    {
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);

        $this->assertSame(12.0, $resolve->execute('2026-07-30'));

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 15]);

        $this->assertSame(12.0, $resolve->execute('2026-07-30'));
        $this->assertSame(15.0, $resolve->execute('2026-07-31'));

        $this->assertTrue(
            AdministrativeFeeRate::query()
                ->whereDate('effective_from', '2026-07-31')
                ->where('percentage', 15)
                ->exists()
        );
    }

    public function test_same_day_second_edit_only_updates_tomorrow_rate(): void
    {
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);
        $schedule = app(ScheduleAdminFeePercentageChangeAction::class);

        $schedule->execute(15);
        $schedule->execute(20);

        $this->assertSame(12.0, $resolve->execute('2026-07-30'));
        $this->assertSame(20.0, $resolve->execute('2026-07-31'));

        $this->assertSame(
            1,
            AdministrativeFeeRate::query()->whereDate('effective_from', '2026-07-31')->count()
        );
    }

    public function test_change_pins_today_rate_so_a_rewritten_history_row_cannot_leak(): void
    {
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 15]);

        AdministrativeFeeRate::query()
            ->whereDate('effective_from', '<', '2026-07-30')
            ->update(['percentage' => 15]);

        $this->assertSame(12.0, $resolve->execute('2026-07-30'));
        $this->assertSame(15.0, $resolve->execute('2026-07-31'));
    }

    public function test_historical_date_unaffected_after_setting_change(): void
    {
        $resolve = app(ResolveEffectiveAdminFeePercentageAction::class);

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 15]);

        $this->assertSame(12.0, $resolve->execute('2026-07-15'));
        $this->assertSame(12.0, $resolve->execute('2026-07-29'));
    }
}
