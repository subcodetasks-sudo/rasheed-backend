<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Services\NotificationSseService;
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
        $user = $this->actAs('finance');
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
        $this->assertStringStartsWith('Urgent details', $byTitle['Urgent title']['details']);
        $this->assertSame($project->id, $byTitle['Urgent title']['project']['id']);
        $this->assertSame('مشروع إشعار', $byTitle['Urgent title']['project']['name']);
        $this->assertSame($user->full_name, $byTitle['Urgent title']['actor']['full_name']);

        $this->assertSame('warning', $byTitle['Activity title']['type']);
        $this->assertSame($project->id, $byTitle['Activity title']['project']['id']);

        $this->assertSame('info', $byTitle['Info title']['type']);
        $this->assertNull($byTitle['Info title']['project']);
        $this->assertNull($byTitle['Urgent title']['read_at']);
    }

    public function test_mark_one_and_all_read_are_per_user(): void
    {
        $this->actAs('finance');

        $first = app(NotificationService::class)->notifyInfo('First', 'details');
        $second = app(NotificationService::class)->notifyWarning('Second', 'details');

        $this->postJson('/api/v1/notifications/'.$first->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('success', true);

        $byId = collect($this->getJson('/api/v1/notifications')->json('data'))->keyBy('id');
        $this->assertNotNull($byId[$first->id]['read_at']);
        $this->assertNull($byId[$second->id]['read_at']);

        $this->getJson('/api/v1/notifications?filter[unread]=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $this->getJson('/api/v1/notifications?filter[unread]=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Role::findOrCreate('inventory', 'web');
        $other = User::factory()->create();
        $other->assignRole('inventory');
        Sanctum::actingAs($other);

        $otherList = collect($this->getJson('/api/v1/notifications')->json('data'))->keyBy('id');
        $this->assertNull($otherList[$first->id]['read_at']);
        $this->assertNull($otherList[$second->id]['read_at']);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications/1')->assertUnauthorized();
    }

    public function test_show_rejects_user_without_allowed_role(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $notification = app(NotificationService::class)->notifyInfo('Hidden', 'details');

        $this->getJson('/api/v1/notifications/'.$notification->id)->assertForbidden();
    }

    public function test_show_returns_notification_and_read_at_without_marking_read(): void
    {
        $this->actAs('finance');

        $notification = app(NotificationService::class)->notifyDanger('Show me', 'details');

        $this->getJson('/api/v1/notifications/'.$notification->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.type', 'urgent')
            ->assertJsonPath('data.title', 'Show me')
            ->assertJsonPath('data.read_at', null);

        $this->postJson('/api/v1/notifications/'.$notification->id.'/read')->assertOk();

        $this->getJson('/api/v1/notifications/'.$notification->id)
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.type', 'urgent');

        $this->assertNotNull(
            $this->getJson('/api/v1/notifications/'.$notification->id)->json('data.read_at')
        );
    }

    public function test_show_returns_404_for_missing_notification(): void
    {
        $this->actAs('super-admin');

        $this->getJson('/api/v1/notifications/999999')->assertNotFound();
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

        $this->getJson('/api/v1/notifications?filter[type]=warning')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'warning');

        $this->getJson('/api/v1/notifications?filter[type]=info')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'info');
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
            ->assertJsonPath('data.warning', 3)
            ->assertJsonPath('data.info', 1);
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
        $this->assertStringContainsString('id: notification-'.$first->id, $body);
        $this->assertStringContainsString('id: notification-'.$second->id, $body);
        $this->assertStringContainsString('"type":"urgent"', $body);
        $this->assertStringContainsString('"type":"info"', $body);
        $this->assertStringContainsString('"read_at":null', $body);
        $this->assertStringContainsString(': heartbeat', $body);
        $this->assertStringContainsString('event: stream.ended', $body);
    }

    public function test_stream_accepts_prefixed_last_event_id(): void
    {
        $this->actAs('finance');

        config([
            'notifications.sse.poll_seconds' => 0,
            'notifications.sse.heartbeat_seconds' => 1,
            'notifications.sse.max_duration_seconds' => 2,
            'notifications.sse.replay_limit' => 100,
        ]);

        $first = app(NotificationService::class)->notifyDanger('A', 'd');
        $second = app(NotificationService::class)->notifyInfo('B', 'd');

        $body = $this->withHeaders([
            'Accept' => 'text/event-stream',
            'Last-Event-ID' => 'notification-'.$first->id,
        ])->get('/api/v1/notifications/stream')->streamedContent();

        $this->assertStringContainsString('id: notification-'.$second->id, $body);
        $this->assertStringNotContainsString('id: notification-'.$first->id."\n", $body);
    }

    public function test_announce_waits_until_transaction_commits(): void
    {
        $this->actAs('super-admin');

        \Illuminate\Support\Facades\Event::fake([
            \Modules\Notifications\Events\NotificationCreated::class,
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();

        app(NotificationService::class)->notifyInfo('Inside txn', 'details');

        $this->assertNull(cache()->get(NotificationSseService::LATEST_ID_CACHE_KEY));
        \Illuminate\Support\Facades\Event::assertNotDispatched(
            \Modules\Notifications\Events\NotificationCreated::class
        );

        \Illuminate\Support\Facades\DB::commit();

        $this->assertNotNull(cache()->get(NotificationSseService::LATEST_ID_CACHE_KEY));
        \Illuminate\Support\Facades\Event::assertDispatched(
            \Modules\Notifications\Events\NotificationCreated::class
        );
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
