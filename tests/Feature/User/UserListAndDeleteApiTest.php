<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserListAndDeleteApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(?User $user = null): User
    {
        $role = Role::findOrCreate('super-admin', 'web');
        $user ??= User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_auth_users_list_excludes_logged_in_user(): void
    {
        $admin = $this->actAsSuperAdmin();
        $other = User::factory()->create(['status' => 'active', 'full_name' => 'Other User']);

        $response = $this->getJson('/api/v1/auth/users');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($other->uuid, $ids);
        $this->assertNotContains($admin->uuid, $ids);
    }

    public function test_authorization_users_list_excludes_logged_in_user(): void
    {
        $admin = $this->actAsSuperAdmin();
        $other = User::factory()->create(['status' => 'active', 'full_name' => 'Other User']);

        $response = $this->getJson('/api/v1/users');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($other->uuid, $ids);
        $this->assertNotContains($admin->uuid, $ids);
    }

    public function test_super_admin_can_delete_another_user(): void
    {
        $this->actAsSuperAdmin();
        $other = User::factory()->create(['status' => 'active']);

        $this->deleteJson('/api/v1/auth/users/'.$other->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', ['uuid' => $other->uuid]);
    }

    public function test_super_admin_cannot_delete_own_account(): void
    {
        $admin = $this->actAsSuperAdmin();

        $this->deleteJson('/api/v1/auth/users/'.$admin->uuid)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('messages.cannot_delete_own_account'));

        $this->assertDatabaseHas('users', [
            'uuid' => $admin->uuid,
            'deleted_at' => null,
        ]);
    }

    public function test_authorization_destroy_rejects_self_delete(): void
    {
        $admin = $this->actAsSuperAdmin();

        $this->deleteJson('/api/v1/users/'.$admin->uuid)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('messages.cannot_delete_own_account'));

        $this->assertDatabaseHas('users', [
            'uuid' => $admin->uuid,
            'deleted_at' => null,
        ]);
    }

    public function test_authorization_destroy_deletes_another_user(): void
    {
        $this->actAsSuperAdmin();
        $other = User::factory()->create(['status' => 'active']);

        $this->deleteJson('/api/v1/users/'.$other->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', ['uuid' => $other->uuid]);
    }
}
