<?php

namespace Tests\Unit\Project;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Project\Actions\Project\ResolveTotalOperationalDeductionAction;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;
use Tests\TestCase;

class ResolveTotalOperationalDeductionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_current_month_relative_employee_sum(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        try {
            MonthlyEmployeeSetting::query()->create([
                'year' => 2026,
                'month' => 8,
                'fixed_workers' => 100,
                'media_staff' => 200.5,
                'administrative_staff' => 0,
                'variable_workers' => 0,
                'speakers' => 0,
                'cooks' => 0,
            ]);

            $action = app(ResolveTotalOperationalDeductionAction::class);

            $this->assertSame(300.5, $action->execute());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_returns_zero_when_no_current_month_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        try {
            MonthlyEmployeeSetting::query()->create([
                'year' => 2026,
                'month' => 7,
                'fixed_workers' => 999,
                'media_staff' => 0,
                'administrative_staff' => 0,
                'variable_workers' => 0,
                'speakers' => 0,
                'cooks' => 0,
            ]);

            $action = app(ResolveTotalOperationalDeductionAction::class);

            $this->assertSame(0.0, $action->execute());
        } finally {
            Carbon::setTestNow();
        }
    }
}
