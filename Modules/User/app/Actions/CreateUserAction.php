<?php

namespace Modules\User\app\Actions;

use Modules\User\app\Events\UserCreated;
use Modules\User\app\Models\User;

class CreateUserAction
{
    public function execute(array $data): User
    {
        $user = User::create([
            'full_name' => $data['full_name'],
            'user_name' => $data['user_name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'status' => 'active',
        ]);

        $user->assignRole($data['role']);
        $user->load('roles');

        UserCreated::dispatch($user);

        return $user;
    }
}
