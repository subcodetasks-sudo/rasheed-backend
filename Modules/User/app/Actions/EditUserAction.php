<?php

namespace Modules\User\app\Actions;

use Modules\User\app\Events\UserUpdated;
use Modules\User\app\Models\User;

class EditUserAction
{
    public function execute(User $user, array $data): User
    {
        if (isset($data['name']) && ! isset($data['full_name'])) {
            $data['full_name'] = $data['name'];
        }
        $fillable = array_intersect_key($data, array_flip(['full_name', 'user_name', 'email', 'phone', 'password']));
        $user->updateOrFail($fillable);
        $user->refresh();

        if (isset($data['role'])) {
            $user->syncRoles($data['role']);
        }

        $user->load('roles');
        UserUpdated::dispatch($user);

        return $user;
    }
}
