<?php

namespace Modules\Notifications\Actions;

use Modules\Notifications\Models\Notification;
use Modules\Notifications\Models\NotificationRead;
use Modules\User\app\Models\User;

class MarkNotificationReadAction
{
    public function execute(Notification $notification, User $user): Notification
    {
        NotificationRead::query()->updateOrCreate(
            [
                'notification_id' => $notification->id,
                'user_id' => $user->uuid,
            ],
            [
                'read_at' => now(),
            ]
        );

        $notification->load([
            'project',
            'reads' => fn ($query) => $query->where('user_id', $user->uuid),
        ]);

        return $notification;
    }
}
