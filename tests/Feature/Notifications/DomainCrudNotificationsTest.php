<?php

namespace Tests\Feature\Notifications;

use App\Support\ArabicLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Services\NotificationService;
use Modules\Project\Models\Project;
use Modules\Settings\Models\SystemGeneralSetting;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DomainCrudNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(string $fullName = 'Admin Actor'): User
    {
        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create(['full_name' => $fullName]);
        $user->assignRole('super-admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_category_create_notifies_with_actor_and_quote_free_details(): void
    {
        $actor = $this->actAsSuperAdmin('أحمد المدير');

        $this->postJson('/api/v1/categories', [
            'name' => 'صحة',
        ])->assertCreated();

        $notification = Notification::query()->latest('id')->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationType::Activity, $notification->type);
        $this->assertSame($actor->uuid, $notification->meta['actor_id']);
        $this->assertSame('أحمد المدير', $notification->meta['actor_name']);
        $this->assertStringContainsString('صحة', $notification->message);
        $this->assertStringContainsString('أحمد المدير', $notification->message);
        $this->assertStringNotContainsString('"صحة"', $notification->message);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.actor.full_name', 'أحمد المدير')
            ->assertJsonPath('data.0.actor.uuid', $actor->uuid);
    }

    public function test_user_create_via_auth_path_notifies(): void
    {
        $this->actAsSuperAdmin('Creator Admin');
        Role::findOrCreate('finance', 'web');

        $this->postJson('/api/v1/auth/users', [
            'full_name' => 'New Finance',
            'user_name' => 'new_finance',
            'email' => 'new_finance@test.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role' => 'finance',
        ])->assertSuccessful();

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Activity->value,
            'title' => ArabicLocale::trans('messages.notification_user_created_title'),
        ]);

        $message = Notification::query()->where('title', ArabicLocale::trans('messages.notification_user_created_title'))->value('message');
        $this->assertStringContainsString('New Finance', (string) $message);
        $this->assertStringContainsString('Creator Admin', (string) $message);
        $this->assertStringNotContainsString('"New Finance"', (string) $message);
    }

    public function test_general_settings_update_notifies(): void
    {
        $this->actAsSuperAdmin('Settings Admin');
        SystemGeneralSetting::singleton();

        $this->putJson('/api/v1/settings/general', [
            'organization_name' => 'Rashid Org',
        ])->assertOk();

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Activity->value,
            'title' => ArabicLocale::trans('messages.notification_general_settings_updated_title'),
        ]);
    }

    public function test_inventory_category_create_notifies(): void
    {
        Role::findOrCreate('inventory', 'web');
        $user = User::factory()->create(['full_name' => 'Inventory Admin']);
        $user->assignRole('inventory');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/inventory/categories', [
            'name' => 'Office',
        ])->assertCreated();

        $notification = Notification::query()
            ->where('title', ArabicLocale::trans('messages.notification_inventory_category_created_title'))
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Inventory Admin', $notification->meta['actor_name']);
        $this->assertStringNotContainsString('"Office"', $notification->message);
    }

    public function test_list_uses_live_project_name_in_details(): void
    {
        $user = $this->actAsSuperAdmin('Finance Actor');
        $project = Project::factory()->create(['name' => 'QA-S2-EXEMPTTOGGLE2']);

        app(NotificationService::class)->notifyActivity(
            __('messages.notification_project_archived_title'),
            __('messages.notification_project_archived_message', ['name' => $project->name]),
            [
                'action' => 'archived',
                'project_id' => $project->id,
            ],
            $project,
        );

        $project->update(['name' => 'مشروع نسبي معفى إداريا']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.project.name', 'مشروع نسبي معفى إداريا')
            ->assertJsonPath('data.0.actor.full_name', $user->full_name);

        $details = $this->getJson('/api/v1/notifications')->json('data.0.details');
        $this->assertStringContainsString('مشروع نسبي معفى إداريا', $details);
        $this->assertStringNotContainsString('QA-S2-EXEMPTTOGGLE2', $details);
        $this->assertStringNotContainsString('"', $details);
    }

    public function test_notification_service_appends_actor_without_quotes(): void
    {
        $this->actAsSuperAdmin('Actor Name');

        $notification = app(NotificationService::class)->notifyActivity(
            'Title',
            'Project Demo was created.',
        );

        $this->assertSame('Actor Name', $notification->meta['actor_name']);
        $this->assertSame(
            'Project Demo was created '.ArabicLocale::trans('messages.notification_by_actor', ['name' => 'Actor Name']).'.',
            $notification->message
        );
        $this->assertStringNotContainsString('"', $notification->message);
    }
}
