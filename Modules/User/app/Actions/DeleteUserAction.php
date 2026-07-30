<?php

namespace Modules\User\app\Actions;

use Modules\User\app\Models\User;

class DeleteUserAction
{
    public function execute(User $user): void
    {
        $user->delete();
    }
}
