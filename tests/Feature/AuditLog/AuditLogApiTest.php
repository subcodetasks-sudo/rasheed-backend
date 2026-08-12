<?php

namespace Tests\Feature\AuditLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AuditLog\Actions\RecordAuditLogAction;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Enums\AuditSource;
use Modules\User\app\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
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

    private function record(
        User $causer,
        AuditAction $action,
        string $description,
        AuditSource $source = AuditSource::Api,
    ): void {
        app(RecordAuditLogAction::class)->execute(
            action: $action,
            description: $description,
            source: $source,
            causer: $causer,
        );
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
    }

    public function test_finance_and_inventory_cannot_list_audit_logs(): void
    {
        $this->actAsRole('finance');
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        $this->actAsRole('inventory');
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_super_admin_can_list_audit_logs_with_pagination_and_shape(): void
    {
        $admin = $this->actAsRole('super-admin');
        $this->record($admin, AuditAction::Created, 'Created project Alpha');
        $this->record($admin, AuditAction::Login, 'User logged in', AuditSource::User);

        $response = $this->getJson('/api/v1/audit-logs?per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'occurred_at',
                        'user' => ['uuid', 'full_name'],
                        'action',
                        'source',
                        'description',
                        'ip_address',
                    ],
                ],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
                'available_actions',
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertContains(AuditAction::Created->value, $response->json('available_actions'));
        $this->assertSame($admin->uuid, $response->json('data.0.user.uuid'));
        $this->assertSame($admin->full_name, $response->json('data.0.user.full_name'));
    }

    public function test_listing_audit_logs_does_not_create_a_viewed_record(): void
    {
        $this->actAsRole('super-admin');

        $this->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertSame(0, Activity::query()->where('log_name', 'audit')->count());
    }

    public function test_filters_by_user_action_and_date_range(): void
    {
        $admin = $this->actAsRole('super-admin');
        $other = User::factory()->create();

        $this->record($admin, AuditAction::Created, 'Created by admin');
        $this->record($other, AuditAction::Updated, 'Updated by other');
        $this->record($admin, AuditAction::Deleted, 'Deleted by admin');

        $created = Activity::query()->where('event', AuditAction::Created->value)->first();
        $updated = Activity::query()->where('event', AuditAction::Updated->value)->first();
        $deleted = Activity::query()->where('event', AuditAction::Deleted->value)->first();

        $created->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();
        $updated->forceFill(['created_at' => '2026-08-05 10:00:00'])->save();
        $deleted->forceFill(['created_at' => '2026-08-10 10:00:00'])->save();

        $byUser = $this->getJson('/api/v1/audit-logs?filter[user_id]='.$admin->uuid.'&per_page=20')
            ->assertOk();
        $this->assertCount(2, $byUser->json('data'));
        $this->assertTrue(collect($byUser->json('data'))->every(
            fn (array $row) => $row['user']['uuid'] === $admin->uuid
        ));

        $byAction = $this->getJson('/api/v1/audit-logs?filter[action]=updated&per_page=20')
            ->assertOk();
        $this->assertCount(1, $byAction->json('data'));
        $this->assertSame('updated', $byAction->json('data.0.action'));

        $byDate = $this->getJson('/api/v1/audit-logs?filter[created_from]=2026-08-04&filter[created_to]=2026-08-06&per_page=20')
            ->assertOk();
        $this->assertCount(1, $byDate->json('data'));
        $this->assertSame('Updated by other', $byDate->json('data.0.description'));
    }

    public function test_rejects_invalid_action_filter(): void
    {
        $this->actAsRole('super-admin');

        $this->getJson('/api/v1/audit-logs?filter[action]=not-a-real-action')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter.action']);
    }

    public function test_write_methods_are_not_available(): void
    {
        $this->actAsRole('super-admin');

        $this->postJson('/api/v1/audit-logs', [])->assertMethodNotAllowed();
        $this->putJson('/api/v1/audit-logs', [])->assertMethodNotAllowed();
        $this->patchJson('/api/v1/audit-logs', [])->assertMethodNotAllowed();
        $this->deleteJson('/api/v1/audit-logs')->assertMethodNotAllowed();
    }
}
