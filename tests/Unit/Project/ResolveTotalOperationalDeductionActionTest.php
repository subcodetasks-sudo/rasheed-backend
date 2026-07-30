<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Project\Actions\Project\ResolveTotalOperationalDeductionAction;
use Modules\Settings\Services\SettingService;
use Tests\TestCase;

class ResolveTotalOperationalDeductionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_setting_value_when_present(): void
    {
        app(SettingService::class)->update('total_operational_deduction', 1500.5, 'decimal', true);

        $action = app(ResolveTotalOperationalDeductionAction::class);

        $this->assertSame(1500.5, $action->execute());
    }

    public function test_returns_default_when_setting_is_missing(): void
    {
        $action = app(ResolveTotalOperationalDeductionAction::class);

        $this->assertSame(1081.0, $action->execute());
    }
}
