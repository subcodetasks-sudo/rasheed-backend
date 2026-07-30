<?php

namespace Modules\User\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\User\app\Models\RefreshToken;
use Modules\User\app\Models\User;
use Modules\User\app\Transformers\UserResource;

class AuthService
{
    public function login(array $data): array
    {
        if (! Auth::once($data)) {
            throw ValidationException::withMessages(['user_name' => __('auth.failed')]);
        }

        $user = Auth::user();

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['user_name' => __('auth.errors.unauthorized')]);
        }

        $user->updateLastLogin();

        return $this->getUserDataWithToken($user);
    }

    public function logout($user): void
    {
        $user->tokens()->delete();
        RefreshToken::query()
            ->where('user_id', $user->uuid)
            ->where('is_revoked', false)
            ->update(['is_revoked' => true]);
    }

    public function refresh(string $refreshToken): array
    {
        $stored = RefreshToken::findValidToken($refreshToken);

        if (! $stored) {
            throw ValidationException::withMessages([
                'refresh_token' => [__('auth.invalid_or_expired_refresh_token')],
            ]);
        }

        $user = $stored->user;

        if (! $user || ! $user->isActive()) {
            $stored->update(['is_revoked' => true]);

            throw ValidationException::withMessages([
                'refresh_token' => [__('auth.invalid_or_expired_refresh_token')],
            ]);
        }

        $stored->update(['is_revoked' => true]);
        $user->tokens()->delete();

        return $this->getUserDataWithToken($user);
    }

    public function getUserDataWithToken(User $user): array
    {
        $token = $user->createToken('auth_token', ['*'], now()->addHours(2))->plainTextToken;

        return [
            'user' => new UserResource($user),
            'token' => $token,
            'refresh_token' => RefreshToken::createToken($user),
        ];
    }
}
