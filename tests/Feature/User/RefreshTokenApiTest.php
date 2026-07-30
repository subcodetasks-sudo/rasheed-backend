<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\app\Models\RefreshToken;
use Modules\User\app\Models\User;
use Tests\TestCase;

class RefreshTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'password' => 'password123',
        ], $attributes));
    }

    public function test_login_returns_access_and_refresh_tokens(): void
    {
        $user = $this->createActiveUser(['user_name' => 'finance_user']);

        $response = $this->postJson('/api/v1/auth/login', [
            'user_name' => 'finance_user',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'refresh_token'],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.refresh_token'));
        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertSame($user->uuid, RefreshToken::query()->value('user_id'));
    }

    public function test_refresh_returns_new_access_and_refresh_tokens_and_revokes_old_refresh_token(): void
    {
        $user = $this->createActiveUser(['user_name' => 'refresh_user']);

        $login = $this->postJson('/api/v1/auth/login', [
            'user_name' => 'refresh_user',
            'password' => 'password123',
        ])->assertOk();

        $oldRefreshToken = $login->json('data.refresh_token');
        $oldAccessToken = $login->json('data.token');

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefreshToken,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'refresh_token'],
            ]);

        $newRefreshToken = $response->json('data.refresh_token');
        $newAccessToken = $response->json('data.token');

        $this->assertNotSame($oldRefreshToken, $newRefreshToken);
        $this->assertNotSame($oldAccessToken, $newAccessToken);

        $this->assertTrue(
            (bool) RefreshToken::query()
                ->where('token', hash('sha256', $oldRefreshToken))
                ->value('is_revoked')
        );

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefreshToken,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['refresh_token']);
    }

    public function test_refresh_rejects_invalid_token(): void
    {
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'not-a-real-token',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['refresh_token']);
    }

    public function test_refresh_rejects_expired_token(): void
    {
        $user = $this->createActiveUser();
        $plain = 'expired-refresh-token-value-0123456789abcdef0123456789ab';

        RefreshToken::query()->create([
            'user_id' => $user->uuid,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->subMinute(),
            'is_revoked' => false,
        ]);

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $plain,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['refresh_token']);
    }

    public function test_refresh_requires_refresh_token(): void
    {
        $this->postJson('/api/v1/auth/refresh', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refresh_token']);
    }

    public function test_logout_revokes_refresh_tokens(): void
    {
        $user = $this->createActiveUser(['user_name' => 'logout_user']);

        $login = $this->postJson('/api/v1/auth/login', [
            'user_name' => 'logout_user',
            'password' => 'password123',
        ])->assertOk();

        $token = $login->json('data.token');
        $refreshToken = $login->json('data.refresh_token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertTrue(
            (bool) RefreshToken::query()
                ->where('token', hash('sha256', $refreshToken))
                ->value('is_revoked')
        );

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['refresh_token']);
    }
}
