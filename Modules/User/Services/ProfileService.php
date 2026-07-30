<?php

namespace Modules\User\Services;

use Modules\User\app\Models\User;
use Modules\User\app\Transformers\UserResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function getProfile(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function updateProfile(User $user, array $data): UserResource
    {
        $user->update($data);
        return new UserResource($user);
    }

    public function updatePassword(User $user, string $currentPassword, string $newPassword): UserResource
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.current_password_incorrect')]
            ]);
        }

        $user->update([
            'password' => Hash::make($newPassword)
        ]);
        
        return new UserResource($user);
    }

    public function uploadAvatar(User $user, $file): UserResource
    {
        $user->clearMediaCollection('avatar');
        $user->addMedia($file)->toMediaCollection('avatar');
        
        return new UserResource($user);
    }

    public function deleteAvatar(User $user): UserResource
    {
        $user->clearMediaCollection('avatar');
        return new UserResource($user);
    }

    public function getPreferences(User $user): array
    {
        return $user->preferences ?? [
            'notifications' => [
                'email' => true,
                'sms' => false,
                'push' => true,
            ],
            'ui' => [
                'theme' => 'system',
                'compact_mode' => false,
            ]
        ];
    }

    public function updatePreferences(User $user, array $preferences): array
    {
        $currentPreferences = $user->preferences ?? [];
        $user->preferences = array_merge($currentPreferences, $preferences);
        $user->save();
        
        return $user->preferences;
    }

    public function softDelete(User $user, string $password): void
    {
        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('auth.password_incorrect')]
            ]);
        }

        $user->delete();
    }
}