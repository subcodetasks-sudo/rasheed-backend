<?php

namespace Tests\Feature\Settings;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Dashboard\Events\DashboardUpdated;
use Modules\Project\Models\AdministrativeFeeRate;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemGeneralSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/settings/general';

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_finance_gets_403(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_show_returns_singleton_defaults(): void
    {
        $this->actAs('super-admin');

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Rashid Financial')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.admin_fee_percentage', 12);
    }

    public function test_can_update_all_general_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00'));

        try {
            $this->actAs('super-admin');

            $this->putJson(self::ENDPOINT, [
                'organization_name' => 'Org X',
                'currency' => 'USD',
                'admin_fee_percentage' => 15,
            ])
                ->assertOk()
                ->assertJsonPath('data.organization_name', 'Org X')
                ->assertJsonPath('data.currency', 'USD')
                ->assertJsonPath('data.admin_fee_percentage', 15);

            $this->assertDatabaseHas('system_general_settings', [
                'organization_name' => 'Org X',
                'currency' => 'USD',
                'admin_fee_percentage' => 15,
            ]);

            $this->assertTrue(
                AdministrativeFeeRate::query()
                    ->whereDate('effective_from', '2026-07-31')
                    ->where('percentage', 15)
                    ->exists()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_partial_update_organization_name_only(): void
    {
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'organization_name' => 'Only Name',
        ])
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Only Name')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.admin_fee_percentage', 12);
    }

    public function test_rejects_empty_organization_name_and_unsupported_currency(): void
    {
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'organization_name' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['organization_name']);

        $this->putJson(self::ENDPOINT, [
            'currency' => 'EUR',
        ])->assertStatus(422)->assertJsonValidationErrors(['currency']);

        $this->putJson(self::ENDPOINT, [
            'admin_fee_percentage' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_fee_percentage']);
    }

    public function test_identical_admin_fee_does_not_reschedule_rates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00'));

        try {
            $this->actAs('super-admin');

            $this->putJson(self::ENDPOINT, ['admin_fee_percentage' => 15])->assertOk();
            $countAfterFirst = AdministrativeFeeRate::query()->count();

            $this->putJson(self::ENDPOINT, ['admin_fee_percentage' => 15])->assertOk();

            $this->assertSame($countAfterFirst, AdministrativeFeeRate::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dispatches_system_general_settings_updated(): void
    {
        Event::fake([SystemGeneralSettingsUpdated::class, DashboardUpdated::class]);
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'organization_name' => 'Broadcast Org',
        ])->assertOk();

        Event::assertDispatched(SystemGeneralSettingsUpdated::class);
    }

    public function test_legacy_key_value_endpoints_are_removed(): void
    {
        $this->actAs('super-admin');

        $this->getJson('/api/v1/settings')->assertNotFound();
        $this->postJson('/api/v1/settings/app_name', [
            'value' => 'Nope',
        ])->assertNotFound();
    }
}
