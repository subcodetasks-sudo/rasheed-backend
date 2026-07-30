<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;
use Modules\Settings\Services\SettingService;
use Tests\TestCase;

class ResolveAdminFeePercentageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_setting_value_when_present(): void
    {
        app(SettingService::class)->update('admin_fee_percentage', 15.5, 'decimal', true);

        $action = app(ResolveAdminFeePercentageAction::class);

        $this->assertSame(15.5, $action->execute());
    }

    public function test_returns_default_when_setting_is_missing(): void
    {
        $action = app(ResolveAdminFeePercentageAction::class);

        $this->assertSame(12.0, $action->execute());
    }
}
