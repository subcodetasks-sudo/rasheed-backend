<?php

namespace Modules\Notifications\Listeners;

use Modules\Notifications\Services\NotificationService;
use Modules\User\app\Events\UserCreated;
use Modules\User\app\Events\UserDeleted;
use Modules\User\app\Events\UserStatusUpdated;
use Modules\User\app\Events\UserUpdated;

class NotifyUserActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(UserCreated|UserUpdated|UserDeleted|UserStatusUpdated $event): void
    {
        if ($event instanceof UserDeleted) {
            $this->notificationService->notifyActivity(
                __('messages.notification_user_deleted_title'),
                __('messages.notification_user_deleted_message', ['name' => $event->userFullName]),
                [
                    'action' => 'deleted',
                    'user_uuid' => $event->userUuid,
                ],
            );

            return;
        }

        $user = $event->user;

        if ($event instanceof UserStatusUpdated) {
            $this->notificationService->notifyActivity(
                __('messages.notification_user_status_updated_title'),
                __('messages.notification_user_status_updated_message', [
                    'name' => $user->full_name,
                    'status' => $user->status,
                ]),
                [
                    'action' => 'status_updated',
                    'user_uuid' => $user->uuid,
                    'status' => $user->status,
                ],
                $user,
            );

            return;
        }

        [$titleKey, $messageKey, $action] = $event instanceof UserCreated
            ? ['notification_user_created_title', 'notification_user_created_message', 'created']
            : ['notification_user_updated_title', 'notification_user_updated_message', 'updated'];

        $this->notificationService->notifyActivity(
            __('messages.'.$titleKey),
            __('messages.'.$messageKey, ['name' => $user->full_name]),
            [
                'action' => $action,
                'user_uuid' => $user->uuid,
            ],
            $user,
        );
    }
}
