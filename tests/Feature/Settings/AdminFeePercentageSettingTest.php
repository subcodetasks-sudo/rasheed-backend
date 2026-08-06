<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Settings\Models\SystemGeneralSetting;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminFeePercentageSettingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/settings/general';

    private function actAsSettingsEditor(): User
    {
        $role = Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedAdminFeeSetting(float|string $value = 12): void
    {
        SystemGeneralSetting::singleton()->update([
            'admin_fee_percentage' => $value,
        ]);
    }

    public function test_authorized_user_can_update_admin_fee_percentage(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSettingsEditor();

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.admin_fee_percentage', 15);

        $this->assertDatabaseHas('system_general_settings', [
            'admin_fee_percentage' => 15,
        ]);
    }

    public function test_admin_fee_percentage_validation_rejects_invalid_values(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSettingsEditor();

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_fee_percentage']);

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_fee_percentage']);

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => 101,
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_fee_percentage']);
    }

    public function test_admin_fee_percentage_accepts_valid_boundary_values(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSettingsEditor();

        foreach ([0, 5, 8.5, 10, 12, 15, 20, 100] as $value) {
            $this->putJson(self::ENDPOINT, [
                'admin_fee_percentage' => $value,
            ])->assertOk()
                ->assertJsonPath('data.admin_fee_percentage', $value);
        }
    }

    public function test_unauthorized_users_cannot_update_admin_fee_percentage(): void
    {
        $this->seedAdminFeeSetting(12);

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => 15,
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => 15,
        ])->assertForbidden();

        $this->assertDatabaseHas('system_general_settings', [
            'admin_fee_percentage' => 12,
        ]);
    }
}
