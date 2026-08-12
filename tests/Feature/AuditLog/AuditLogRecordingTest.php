<?php

namespace Tests\Feature\AuditLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AuditLog\Actions\RecordAuditLogAction;
use Modules\AuditLog\Enums\AuditAction;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\Inventory\Models\InventoryCategory;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\Settings\Services\SettingService;
use Modules\User\app\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function actAsRole(string $roleName): User
    {
        Role::findOrCreate($roleName, 'web');

        $user = User::factory()->create();
        $user->assignRole($roleName);
        Sanctum::actingAs($user);

        return $user;
    }

    private function auditRows(?string $action = null)
    {
        $query = Activity::query()->where('log_name', 'audit');

        if ($action !== null) {
            $query->where('event', $action);
        }

        return $query->get();
    }

    public function test_login_and_logout_create_user_source_records(): void
    {
        $user = User::factory()->create([
            'user_name' => 'audit_login',
            'password' => 'password123',
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'user_name' => 'audit_login',
            'password' => 'password123',
        ])->assertOk();

        $loginRow = $this->auditRows(AuditAction::Login->value)->first();
        $this->assertNotNull($loginRow);
        $this->assertSame('user', $loginRow->getExtraProperty('source'));
        $this->assertSame($user->uuid, $loginRow->causer_id);

        $this->withToken($login->json('data.token'))
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $logoutRow = $this->auditRows(AuditAction::Logout->value)->first();
        $this->assertNotNull($logoutRow);
        $this->assertSame('user', $logoutRow->getExtraProperty('source'));
        $this->assertSame($user->uuid, $logoutRow->causer_id);
    }

    public function test_creating_a_project_records_created_action(): void
    {
        $this->actAsRole('super-admin');
        $category = Category::factory()->create(['name' => 'Relief']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Audit Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $row = $this->auditRows(AuditAction::Created->value)
            ->first(fn (Activity $activity) => str_contains($activity->description, 'Audit Project'));

        $this->assertNotNull($row);
        $this->assertSame('api', $row->getExtraProperty('source'));
        $this->assertNotEmpty($row->getExtraProperty('ip'));
    }

    public function test_incoming_stock_records_incoming_action(): void
    {
        $this->actAsRole('inventory');
        $project = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Ink',
            'category_id' => InventoryCategory::factory()->create(['name' => 'supplies'])->id,
            'project_id' => $project->id,
            'unit' => 'bottle',
            'opening_price' => 5,
            'opening_quantity' => 10,
            'minimum_stock_level' => 0,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/inventory/movements/incoming', [
            'inventory_item_id' => $itemId,
            'quantity' => 20,
            'unit_price' => 8,
        ])->assertCreated();

        $this->assertNotNull($this->auditRows(AuditAction::Incoming->value)->first());
        $this->assertNotNull(
            $this->auditRows(AuditAction::Created->value)
                ->first(fn (Activity $activity) => str_contains($activity->description, 'Ink'))
        );
    }

    public function test_contribution_event_records_contribution_action(): void
    {
        $this->actAsRole('super-admin');
        $from = Project::factory()->create();
        $to = Project::factory()->create();

        $settlement = CashStationSettlement::query()->create([
            'year' => 2026,
            'month' => 8,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 150,
            'contribution_type' => ContributionType::FundDeficit,
        ]);

        CashStationSettlementCreated::dispatch($settlement);

        $row = $this->auditRows(AuditAction::Contribution->value)->first();
        $this->assertNotNull($row);
        $this->assertSame('api', $row->getExtraProperty('source'));
    }

    public function test_viewing_dashboard_records_viewed_action(): void
    {
        $this->actAsRole('super-admin');

        $this->getJson('/api/v1/dashboard')->assertOk();

        $row = $this->auditRows(AuditAction::Viewed->value)->first();
        $this->assertNotNull($row);
        $this->assertSame('api', $row->getExtraProperty('source'));
        $this->assertStringContainsString('لوحة التحكم', $row->description);
    }

    public function test_saving_daily_journal_records_saved_and_does_not_change_calculations(): void
    {
        $this->actAsRole('finance');
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'administrative_fee_percentage' => 12,
            'administrative_exempt' => false,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => now()->toDateString(),
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 50,
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertEquals(120, (float) $response->json('data.entries.0.administrative_fee'));
        $this->assertEquals(0, (float) $response->json('data.entries.0.operational_deduction'));
        $this->assertEquals(0, (float) $response->json('data.entries.0.administrative_expense'));
        $this->assertEquals(830, (float) $response->json('data.entries.0.daily_total'));
        $this->assertEquals(830, (float) $response->json('data.entries.0.fund_balance'));
        $this->assertNotNull($this->auditRows(AuditAction::Saved->value)->first());
    }

    public function test_audit_logger_failure_does_not_block_a_write(): void
    {
        $this->mock(RecordAuditLogAction::class, function ($mock) {
            $mock->shouldReceive('execute')->andThrow(new \RuntimeException('audit down'));
        });

        $this->actAsRole('super-admin');
        $category = Category::factory()->create(['name' => 'Relief']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Still Created',
            'category_id' => $category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => false,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Still Created');

        $this->assertDatabaseHas('projects', ['name' => 'Still Created']);
    }
}
