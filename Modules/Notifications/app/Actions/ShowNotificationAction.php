<?php

namespace Modules\Notifications\Actions;

use Modules\Notifications\Models\Notification;
use Modules\User\app\Models\User;

class ShowNotificationAction
{
    public function execute(Notification $notification, User $user): Notification
    {
        $notification->load([
            'project',
            'reads' => fn ($query) => $query->where('user_id', $user->uuid),
        ]);

        return $notification;
    }
}
