<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Settings\app\Models\Setting;
use Modules\Settings\Services\SettingService;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminFeePercentageSettingTest extends TestCase
{
    use RefreshDatabase;

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
        Setting::updateOrCreate(
            ['key' => 'admin_fee_percentage'],
            ['value' => $value, 'type' => 'decimal', 'is_public' => true]
        );

        app(SettingService::class)->update('admin_fee_percentage', $value, 'decimal', true);
    }

    public function test_authorized_user_can_update_admin_fee_percentage(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSettingsEditor();

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => 15,
            'type' => 'decimal',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.admin_fee_percentage', 15);

        $this->assertDatabaseHas('settings', [
            'key' => 'admin_fee_percentage',
            'value' => '15',
            'type' => 'decimal',
        ]);
    }

    public function test_admin_fee_percentage_validation_rejects_invalid_values(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSettingsEditor();

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => 101,
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);
    }

    public function test_admin_fee_percentage_accepts_valid_boundary_values(): void
    {
        $this->seedAdminFeeSetting(12);
        $this->actAsSettingsEditor();

        foreach ([0, 5, 8.5, 10, 12, 15, 20, 100] as $value) {
            $this->postJson('/api/v1/settings/admin_fee_percentage', [
                'value' => $value,
            ])->assertOk()
                ->assertJsonPath('data.admin_fee_percentage', $value);
        }
    }

    public function test_unauthorized_users_cannot_update_admin_fee_percentage(): void
    {
        $this->seedAdminFeeSetting(12);

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => 15,
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/settings/admin_fee_percentage', [
            'value' => 15,
        ])->assertForbidden();

        $this->assertDatabaseHas('settings', [
            'key' => 'admin_fee_percentage',
            'value' => '12',
        ]);
    }
}
