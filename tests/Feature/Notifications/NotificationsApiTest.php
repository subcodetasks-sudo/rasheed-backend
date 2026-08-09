<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Services\NotificationService;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_list_rejects_user_without_allowed_role(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notifications')->assertForbidden();
    }

    public function test_list_returns_mapped_types_project_and_pagination(): void
    {
        $this->actAs('finance');
        $project = Project::factory()->create(['name' => 'مشروع إشعار']);

        app(NotificationService::class)->notifyDanger('Urgent title', 'Urgent details', [
            'project_id' => $project->id,
        ]);
        app(NotificationService::class)->notifyActivity('Activity title', 'Activity details', [], $project);
        app(NotificationService::class)->notifyInfo('Info title', 'Info details');

        $response = $this->getJson('/api/v1/notifications?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->assertCount(2, $response->json('data'));

        $byTitle = collect($this->getJson('/api/v1/notifications?per_page=10')->json('data'))
            ->keyBy('title');

        $this->assertSame('urgent', $byTitle['Urgent title']['type']);
        $this->assertSame('Urgent details', $byTitle['Urgent title']['details']);
        $this->assertSame($project->id, $byTitle['Urgent title']['project']['id']);
        $this->assertSame('مشروع إشعار', $byTitle['Urgent title']['project']['name']);

        $this->assertSame('notification', $byTitle['Activity title']['type']);
        $this->assertSame($project->id, $byTitle['Activity title']['project']['id']);

        $this->assertSame('information', $byTitle['Info title']['type']);
        $this->assertNull($byTitle['Info title']['project']);
    }

    public function test_list_filter_type_uses_page_vocabulary(): void
    {
        $this->actAs('super-admin');

        app(NotificationService::class)->notifyDanger('D', 'd');
        app(NotificationService::class)->notifyWarning('W', 'w');
        app(NotificationService::class)->notifyInfo('I', 'i');

        $this->getJson('/api/v1/notifications?filter[type]=urgent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'urgent');

        $this->getJson('/api/v1/notifications?filter[type]=notification')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'notification');

        $this->getJson('/api/v1/notifications?filter[type]=information')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'information');
    }

    public function test_statistics_match_mapped_buckets(): void
    {
        $this->actAs('inventory');

        app(NotificationService::class)->notifyDanger('d1', 'm');
        app(NotificationService::class)->notifyActivity('a1', 'm');
        app(NotificationService::class)->notifySuccess('s1', 'm');
        app(NotificationService::class)->notifyWarning('w1', 'm');
        app(NotificationService::class)->notifyInfo('i1', 'm');

        $this->getJson('/api/v1/notifications/statistics')
            ->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.urgent', 1)
            ->assertJsonPath('data.notification', 3)
            ->assertJsonPath('data.information', 1);
    }

    public function test_create_endpoint_is_not_exposed(): void
    {
        $this->actAs('super-admin');

        $this->postJson('/api/v1/notifications', [
            'title' => 'manual',
            'message' => 'should fail',
        ])->assertMethodNotAllowed();
    }

    public function test_service_persists_project_id_from_meta_and_subject(): void
    {
        $project = Project::factory()->create();

        $fromMeta = app(NotificationService::class)->notifyInfo('m', 'd', [
            'project_id' => $project->id,
        ]);
        $fromSubject = app(NotificationService::class)->notifyActivity('a', 'd', [], $project);

        $this->assertSame($project->id, $fromMeta->project_id);
        $this->assertSame($project->id, $fromSubject->project_id);
        $this->assertDatabaseHas('activity_notifications', [
            'id' => $fromMeta->id,
            'project_id' => $project->id,
            'type' => NotificationType::Info->value,
        ]);
    }

    public function test_stream_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications/stream')->assertUnauthorized();
    }

    public function test_stream_rejects_user_without_allowed_role(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notifications/stream')->assertForbidden();
    }

    public function test_stream_rejects_json_accept_clients(): void
    {
        $this->actAs('finance');

        $this->getJson('/api/v1/notifications/stream')
            ->assertStatus(406)
            ->assertJsonPath('success', false);
    }

    public function test_stream_emits_sse_events_heartbeat_and_supports_last_event_id(): void
    {
        $this->actAs('finance');

        config([
            'notifications.sse.poll_seconds' => 0,
            'notifications.sse.heartbeat_seconds' => 1,
            'notifications.sse.max_duration_seconds' => 2,
            'notifications.sse.replay_limit' => 100,
            'notifications.sse.batch_limit' => 50,
        ]);

        $first = app(NotificationService::class)->notifyDanger('Stream urgent', 'details', []);
        $second = app(NotificationService::class)->notifyInfo('Stream info', 'details');

        $response = $this->withHeaders([
            'Accept' => 'text/event-stream',
            'Last-Event-ID' => (string) ($first->id - 1),
        ])->get('/api/v1/notifications/stream');

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();

        $this->assertStringContainsString('retry: ', $body);
        $this->assertStringContainsString('event: stream.ready', $body);
        $this->assertStringContainsString('event: notification.created', $body);
        $this->assertStringContainsString('id: '.$first->id, $body);
        $this->assertStringContainsString('id: '.$second->id, $body);
        $this->assertStringContainsString('"type":"urgent"', $body);
        $this->assertStringContainsString('"type":"information"', $body);
        $this->assertStringContainsString(': heartbeat', $body);
    }

    public function test_stream_without_last_event_id_starts_from_latest(): void
    {
        $this->actAs('super-admin');

        config([
            'notifications.sse.poll_seconds' => 0,
            'notifications.sse.heartbeat_seconds' => 1,
            'notifications.sse.max_duration_seconds' => 1,
        ]);

        $existing = app(NotificationService::class)->notifyActivity('Already there', 'details');

        $body = $this->withHeaders(['Accept' => 'text/event-stream'])
            ->get('/api/v1/notifications/stream')
            ->streamedContent();

        $this->assertStringNotContainsString('event: notification.created', $body);
        $this->assertStringContainsString('event: stream.ready', $body);
        $this->assertStringContainsString(': heartbeat', $body);
    }
}
