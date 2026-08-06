<?php

namespace Tests\Unit\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;
use Modules\Settings\Models\SystemGeneralSetting;
use Tests\TestCase;

class ResolveAdminFeePercentageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_general_settings_value_when_present(): void
    {
        SystemGeneralSetting::singleton()->update([
            'admin_fee_percentage' => 15.5,
        ]);

        $action = app(ResolveAdminFeePercentageAction::class);

        $this->assertSame(15.5, $action->execute());
    }

    public function test_returns_default_when_row_is_missing(): void
    {
        SystemGeneralSetting::query()->delete();

        $action = app(ResolveAdminFeePercentageAction::class);

        $this->assertSame(12.0, $action->execute());
    }
}
