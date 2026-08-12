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

class MyActivityLogApiTest extends TestCase
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
        $this->getJson('/api/v1/my-activity-logs')->assertUnauthorized();
    }

    public function test_finance_inventory_and_super_admin_can_list_own_activity(): void
    {
        foreach (['finance', 'inventory', 'super-admin'] as $role) {
            $user = $this->actAsRole($role);

            $this->getJson('/api/v1/my-activity-logs')
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('meta.total', 0)
                ->assertJsonPath('data', []);
        }
    }

    public function test_returns_only_authenticated_user_records(): void
    {
        $finance = $this->actAsRole('finance');
        $other = User::factory()->create();
        Role::findOrCreate('inventory', 'web');
        $other->assignRole('inventory');

        $this->record($finance, AuditAction::Saved, 'Saved by finance');
        $this->record($finance, AuditAction::Login, 'Finance logged in', AuditSource::User);
        $this->record($other, AuditAction::Created, 'Created by other');
        $this->record($other, AuditAction::Outgoing, 'Outgoing by other');

        $response = $this->getJson('/api/v1/my-activity-logs?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'occurred_at',
                        'action',
                        'source',
                        'description',
                        'ip_address',
                    ],
                ],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
                'available_actions',
            ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertTrue(collect($response->json('data'))->every(
            fn (array $row) => $row['user']['uuid'] === $finance->uuid
        ));
        $this->assertFalse(collect($response->json('data'))->contains(
            fn (array $row) => str_contains($row['description'], 'other')
        ));
    }

    public function test_filter_user_id_cannot_reveal_another_users_records(): void
    {
        $finance = $this->actAsRole('finance');
        $other = User::factory()->create();

        $this->record($finance, AuditAction::Updated, 'Mine');
        $this->record($other, AuditAction::Updated, 'Theirs');

        $response = $this->getJson('/api/v1/my-activity-logs?filter[user_id]='.$other->uuid.'&per_page=20')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.description'));
        $this->assertSame($finance->uuid, $response->json('data.0.user.uuid'));
    }

    public function test_filters_by_action_and_date_range(): void
    {
        $user = $this->actAsRole('finance');
        $other = User::factory()->create();

        $this->record($user, AuditAction::Created, 'My created');
        $this->record($user, AuditAction::Updated, 'My updated');
        $this->record($user, AuditAction::Deleted, 'My deleted');
        $this->record($other, AuditAction::Created, 'Other created');

        $mineCreated = Activity::query()->where('description', 'My created')->first();
        $mineUpdated = Activity::query()->where('description', 'My updated')->first();
        $mineDeleted = Activity::query()->where('description', 'My deleted')->first();

        $mineCreated->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();
        $mineUpdated->forceFill(['created_at' => '2026-08-05 10:00:00'])->save();
        $mineDeleted->forceFill(['created_at' => '2026-08-10 10:00:00'])->save();

        $byAction = $this->getJson('/api/v1/my-activity-logs?filter[action]=created&per_page=20')
            ->assertOk();
        $this->assertCount(1, $byAction->json('data'));
        $this->assertSame('My created', $byAction->json('data.0.description'));

        $fromOnly = $this->getJson('/api/v1/my-activity-logs?filter[created_from]=2026-08-05&per_page=20')
            ->assertOk();
        $this->assertCount(2, $fromOnly->json('data'));

        $toOnly = $this->getJson('/api/v1/my-activity-logs?filter[created_to]=2026-08-05&per_page=20')
            ->assertOk();
        $this->assertCount(2, $toOnly->json('data'));

        $combined = $this->getJson('/api/v1/my-activity-logs?filter[action]=updated&filter[created_from]=2026-08-04&filter[created_to]=2026-08-06&per_page=20')
            ->assertOk();
        $this->assertCount(1, $combined->json('data'));
        $this->assertSame('My updated', $combined->json('data.0.description'));
        $this->assertSame('api', $combined->json('data.0.source'));
    }

    public function test_rejects_invalid_action_without_leaking_other_users(): void
    {
        $user = $this->actAsRole('finance');
        $other = User::factory()->create();
        $this->record($other, AuditAction::Created, 'Secret');

        $this->getJson('/api/v1/my-activity-logs?filter[action]=not-a-real-action')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter.action']);
    }

    public function test_listing_own_activity_does_not_create_a_viewed_record(): void
    {
        $this->actAsRole('finance');

        $this->getJson('/api/v1/my-activity-logs')->assertOk();

        $this->assertSame(0, Activity::query()->where('log_name', 'audit')->count());
    }

    public function test_write_methods_are_not_available(): void
    {
        $this->actAsRole('finance');

        $this->postJson('/api/v1/my-activity-logs', [])->assertMethodNotAllowed();
        $this->putJson('/api/v1/my-activity-logs', [])->assertMethodNotAllowed();
        $this->patchJson('/api/v1/my-activity-logs', [])->assertMethodNotAllowed();
        $this->deleteJson('/api/v1/my-activity-logs')->assertMethodNotAllowed();
    }

    public function test_finance_still_cannot_access_full_audit_log(): void
    {
        $this->actAsRole('finance');

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_source_and_ip_are_returned(): void
    {
        $user = $this->actAsRole('inventory');
        $this->record($user, AuditAction::Login, 'Logged in', AuditSource::User);
        $this->record($user, AuditAction::Incoming, 'Incoming stock');

        $rows = collect($this->getJson('/api/v1/my-activity-logs?per_page=20')->assertOk()->json('data'))
            ->keyBy('action');

        $this->assertSame('user', $rows['login']['source']);
        $this->assertSame('api', $rows['incoming']['source']);
        $this->assertNotEmpty($rows['login']['ip_address']);
        $this->assertNotEmpty($rows['incoming']['description']);
    }
}
