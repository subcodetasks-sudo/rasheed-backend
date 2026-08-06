<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;
use Modules\Project\Actions\Project\ScheduleOperationalDeductionChangeAction;
use Modules\Project\Models\OperationalDeductionRate;
use Modules\Settings\Actions\UpsertMonthlyEmployeeSettingsAction;
use Tests\TestCase;

class OperationalDeductionEffectiveDateTest extends TestCase
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

    public function test_monthly_employee_change_keeps_today_and_applies_tomorrow(): void
    {
        $resolve = app(ResolveEffectiveOperationalDeductionAction::class);

        $this->assertSame(1081.0, $resolve->execute('2026-07-30'));

        $this->upsertRelativePool(2000);

        $this->assertSame(1081.0, $resolve->execute('2026-07-30'));
        $this->assertSame(2000.0, $resolve->execute('2026-07-31'));

        $this->assertTrue(
            OperationalDeductionRate::query()
                ->whereDate('effective_from', '2026-07-31')
                ->where('amount', 2000)
                ->exists()
        );
    }

    public function test_same_day_second_edit_only_updates_tomorrow_rate(): void
    {
        $resolve = app(ResolveEffectiveOperationalDeductionAction::class);
        $schedule = app(ScheduleOperationalDeductionChangeAction::class);

        $schedule->execute(2000);
        $schedule->execute(2500);

        $this->assertSame(1081.0, $resolve->execute('2026-07-30'));
        $this->assertSame(2500.0, $resolve->execute('2026-07-31'));

        $this->assertSame(
            1,
            OperationalDeductionRate::query()->whereDate('effective_from', '2026-07-31')->count()
        );
    }

    public function test_change_pins_today_rate_so_a_rewritten_history_row_cannot_leak(): void
    {
        $resolve = app(ResolveEffectiveOperationalDeductionAction::class);

        app(UpsertMonthlyEmployeeSettingsAction::class)->execute(7, 2026, $this->categoryAmounts(2000));

        // Simulate the open-ended history row being rewritten after a pool change.
        OperationalDeductionRate::query()
            ->whereDate('effective_from', '<', '2026-07-30')
            ->update(['amount' => 2000]);

        $this->assertSame(1081.0, $resolve->execute('2026-07-30'));
        $this->assertSame(2000.0, $resolve->execute('2026-07-31'));
    }

    public function test_historical_date_unaffected_after_monthly_employee_change(): void
    {
        $resolve = app(ResolveEffectiveOperationalDeductionAction::class);

        $this->upsertRelativePool(2000);

        $this->assertSame(1081.0, $resolve->execute('2026-07-15'));
        $this->assertSame(1081.0, $resolve->execute('2026-07-29'));
    }

    /**
     * @return array<string, float>
     */
    private function categoryAmounts(float $relative): array
    {
        return [
            'fixed_workers' => $relative,
            'media_staff' => 0,
            'administrative_staff' => 0,
            'variable_workers' => 0,
            'speakers' => 0,
            'cooks' => 0,
        ];
    }

    private function upsertRelativePool(float $relative): void
    {
        app(UpsertMonthlyEmployeeSettingsAction::class)->execute(7, 2026, $this->categoryAmounts($relative));
    }
}
