<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Settings\app\Models\Setting;
use Modules\Settings\Services\SettingService;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TotalOperationalDeductionSettingTest extends TestCase
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

    private function seedTotalOperationalDeduction(float|string $value = 1081): void
    {
        Setting::updateOrCreate(
            ['key' => 'total_operational_deduction'],
            ['value' => $value, 'type' => 'decimal', 'is_public' => true]
        );

        app(SettingService::class)->update('total_operational_deduction', $value, 'decimal', true);
    }

    public function test_authorized_user_can_update_total_operational_deduction(): void
    {
        $this->seedTotalOperationalDeduction(1081);
        $this->actAsSettingsEditor();

        $this->postJson('/api/v1/settings/total_operational_deduction', [
            'value' => 1500,
            'type' => 'decimal',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_operational_deduction', 1500);

        $this->assertDatabaseHas('settings', [
            'key' => 'total_operational_deduction',
            'value' => '1500',
            'type' => 'decimal',
        ]);
    }

    public function test_total_operational_deduction_validation_rejects_invalid_values(): void
    {
        $this->seedTotalOperationalDeduction(1081);
        $this->actAsSettingsEditor();

        $this->postJson('/api/v1/settings/total_operational_deduction', [
            'value' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);

        $this->postJson('/api/v1/settings/total_operational_deduction', [
            'value' => -10,
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);

        $this->postJson('/api/v1/settings/total_operational_deduction', [
            'value' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors(['value']);
    }

    public function test_unauthorized_users_cannot_update_total_operational_deduction(): void
    {
        $this->seedTotalOperationalDeduction(1081);

        $this->postJson('/api/v1/settings/total_operational_deduction', [
            'value' => 1500,
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/settings/total_operational_deduction', [
            'value' => 1500,
        ])->assertForbidden();
    }
}
