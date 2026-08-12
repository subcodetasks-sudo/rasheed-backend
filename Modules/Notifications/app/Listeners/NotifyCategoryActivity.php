<?php

namespace Modules\Notifications\Listeners;

use App\Support\ArabicLocale;
use Modules\Notifications\Services\NotificationService;
use Modules\Project\Events\CategoryCreated;
use Modules\Project\Events\CategoryDeleted;
use Modules\Project\Events\CategoryUpdated;

class NotifyCategoryActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(CategoryCreated|CategoryUpdated|CategoryDeleted $event): void
    {
        if ($event instanceof CategoryDeleted) {
            $this->notificationService->notifyActivity(
                ArabicLocale::trans('messages.notification_category_deleted_title'),
                ArabicLocale::trans('messages.notification_category_deleted_message', ['name' => $event->categoryName]),
                [
                    'action' => 'deleted',
                    'category_id' => $event->categoryId,
                ],
            );

            return;
        }

        $category = $event->category;
        [$titleKey, $messageKey, $action] = $event instanceof CategoryCreated
            ? ['notification_category_created_title', 'notification_category_created_message', 'created']
            : ['notification_category_updated_title', 'notification_category_updated_message', 'updated'];

        $this->notificationService->notifyActivity(
            ArabicLocale::trans('messages.'.$titleKey),
            ArabicLocale::trans('messages.'.$messageKey, ['name' => $category->name]),
            [
                'action' => $action,
                'category_id' => $category->id,
            ],
            $category,
        );
    }
}
