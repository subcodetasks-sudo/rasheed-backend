<?php

namespace Modules\Authorization\app\Services;

use Illuminate\Support\Facades\Hash;
use Modules\User\app\Models\User;

class UserService
{
    public function list()
    {
        return User::with('roles')->get();
    }

    public function create(array $data)
    {
        $user = User::create([
            'full_name' => $data['full_name'],
            'user_name' => $data['user_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        if (!empty($data['role'])) {
            $user->assignRole($data['role']);
        }

        return $user->load('roles');
    }

    public function update($user, array $data)
    {
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        if (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return $user->load('roles');
    }

    public function delete($user)
    {
        return $user->delete();
    }
}