<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCreateApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): User
    {
        $role = Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('finance', 'web');

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_super_admin_can_create_user_with_optional_phone(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->postJson('/api/v1/auth/users', [
            'full_name' => 'Finance Manager',
            'user_name' => 'finance_user',
            'email' => 'finance@test.com',
            'phone' => '0599000000',
            'password' => 'Password1!',
            'role' => 'finance',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', '0599000000')
            ->assertJsonPath('data.user_name', 'finance_user');

        $this->assertDatabaseHas('users', [
            'user_name' => 'finance_user',
            'phone' => '0599000000',
        ]);
    }

    public function test_super_admin_can_create_user_without_phone(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->postJson('/api/v1/auth/users', [
            'full_name' => 'Finance Manager',
            'user_name' => 'finance_user',
            'email' => 'finance@test.com',
            'password' => 'Password1!',
            'role' => 'finance',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', null);

        $this->assertDatabaseHas('users', [
            'user_name' => 'finance_user',
            'phone' => null,
        ]);
    }
}
