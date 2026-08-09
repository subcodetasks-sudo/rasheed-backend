<?php

namespace Modules\User\app\Actions;

use Modules\User\app\Events\UserDeleted;
use Modules\User\app\Models\User;

class DeleteUserAction
{
    public function execute(User $user): void
    {
        $uuid = (string) $user->uuid;
        $fullName = (string) $user->full_name;
        $user->delete();
        UserDeleted::dispatch($uuid, $fullName);
    }
}
