<?php

namespace Tests\Feature\Settings;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\OperationalDeductionRate;
use Modules\Project\Models\Project;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;
use Modules\Settings\app\Models\Setting;
use Modules\Settings\Services\SettingService;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonthlyEmployeeSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/settings/monthly-employees';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertUnauthorized();
    }

    public function test_finance_gets_403(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertForbidden();
    }

    public function test_empty_month_returns_zeros(): void
    {
        $this->actAs('super-admin');

        $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('data.month', 8)
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.relative_deduction', '0.00')
            ->assertJsonPath('data.fixed_project_deductions', '0.00')
            ->assertJsonPath('data.total_daily_operational_deduction', '0.00')
            ->assertJsonPath('data.categories.fixed_workers', '0.00')
            ->assertJsonPath('data.categories.cooks', '0.00');
    }

    public function test_save_categories_computes_relative_fixed_and_total(): void
    {
        $this->actAs('super-admin');

        Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 200,
        ]);
        Project::factory()->create([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 50,
        ]);

        $response = $this->putJson(self::ENDPOINT, [
            'month' => 8,
            'year' => 2026,
            'fixed_workers' => 100,
            'media_staff' => 200,
            'administrative_staff' => 300,
            'variable_workers' => 150,
            'speakers' => 50,
            'cooks' => 25,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.relative_deduction', '825.00')
            ->assertJsonPath('data.fixed_project_deductions', '250.00')
            ->assertJsonPath('data.total_daily_operational_deduction', '1075.00');

        $this->assertDatabaseHas('monthly_employee_settings', [
            'year' => 2026,
            'month' => 8,
            'fixed_workers' => 100,
            'cooks' => 25,
        ]);

        // Current month → updates configured setting + schedules rates
        $this->assertSame(825.0, (float) app(SettingService::class)->get('total_operational_deduction'));
        $this->assertNotNull(
            OperationalDeductionRate::query()
                ->whereDate('effective_from', '2026-08-16')
                ->where('amount', 825)
                ->first()
        );
    }

    public function test_all_zero_categories_allowed(): void
    {
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'month' => 8,
            'year' => 2026,
            'fixed_workers' => 0,
            'media_staff' => 0,
            'administrative_staff' => 0,
            'variable_workers' => 0,
            'speakers' => 0,
            'cooks' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.relative_deduction', '0.00')
            ->assertJsonPath('data.total_daily_operational_deduction', '0.00');
    }

    public function test_past_month_upserts_month_start_rate_without_touching_setting(): void
    {
        $this->actAs('super-admin');

        Setting::updateOrCreate(
            ['key' => 'total_operational_deduction'],
            ['value' => '1081', 'type' => 'decimal', 'is_public' => true]
        );
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $this->putJson(self::ENDPOINT, [
            'month' => 6,
            'year' => 2026,
            'fixed_workers' => 400,
            'media_staff' => 100,
            'administrative_staff' => 0,
            'variable_workers' => 0,
            'speakers' => 0,
            'cooks' => 0,
        ])->assertOk()->assertJsonPath('data.relative_deduction', '500.00');

        $this->assertSame(1081.0, (float) app(SettingService::class)->get('total_operational_deduction'));
        $this->assertNotNull(
            OperationalDeductionRate::query()
                ->whereDate('effective_from', '2026-06-01')
                ->where('amount', 500)
                ->first()
        );
    }

    public function test_future_month_upserts_month_start_rate(): void
    {
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'month' => 10,
            'year' => 2026,
            'fixed_workers' => 10,
            'media_staff' => 20,
            'administrative_staff' => 30,
            'variable_workers' => 40,
            'speakers' => 50,
            'cooks' => 60,
        ])->assertOk()->assertJsonPath('data.relative_deduction', '210.00');

        $this->assertNotNull(
            OperationalDeductionRate::query()
                ->whereDate('effective_from', '2026-10-01')
                ->where('amount', 210)
                ->first()
        );
    }

    public function test_partial_category_payload_defaults_missing_to_zero(): void
    {
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'month' => 8,
            'year' => 2026,
            'fixed_workers' => 75,
        ])
            ->assertOk()
            ->assertJsonPath('data.relative_deduction', '75.00')
            ->assertJsonPath('data.categories.media_staff', '0.00');
    }

    public function test_organization_name_aliases_app_name(): void
    {
        $this->actAs('super-admin');

        Setting::updateOrCreate(
            ['key' => 'app_name'],
            ['value' => 'Rashid Org', 'type' => 'string', 'is_public' => true]
        );
        app(SettingService::class)->update('app_name', 'Rashid Org', 'string', true);

        $service = app(SettingService::class);
        $this->assertSame('Rashid Org', $service->get('organization_name'));

        $this->postJson('/api/v1/settings/organization_name', [
            'value' => 'New Org Name',
            'type' => 'string',
        ])->assertOk();

        $this->assertDatabaseHas('settings', [
            'key' => 'app_name',
            'value' => 'New Org Name',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'organization_name',
        ]);
        $this->assertSame('New Org Name', $service->get('organization_name'));
        $this->assertSame('New Org Name', $service->get('app_name'));

        $index = $this->getJson('/api/v1/settings');
        $index->assertOk();
        $keys = collect($index->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('app_name'));
        $this->assertTrue($keys->contains('organization_name'));
    }

    public function test_show_after_save_returns_persisted_row(): void
    {
        $this->actAs('super-admin');

        $this->putJson(self::ENDPOINT, [
            'month' => 7,
            'year' => 2026,
            'speakers' => 99,
        ])->assertOk();

        $this->getJson(self::ENDPOINT.'?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('data.categories.speakers', '99.00')
            ->assertJsonPath('data.relative_deduction', '99.00');

        $this->assertSame(1, MonthlyEmployeeSetting::query()->count());
    }
}
