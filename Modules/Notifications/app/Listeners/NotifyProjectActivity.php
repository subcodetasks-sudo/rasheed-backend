<?php

namespace Modules\Notifications\Listeners;

use App\Support\ArabicLocale;
use Modules\Notifications\Services\NotificationService;
use Modules\Project\Events\ProjectArchived;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Events\ProjectDeleted;
use Modules\Project\Events\ProjectRestored;
use Modules\Project\Events\ProjectUpdated;

class NotifyProjectActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(ProjectCreated|ProjectUpdated|ProjectArchived|ProjectDeleted|ProjectRestored $event): void
    {
        if ($event instanceof ProjectDeleted) {
            $this->notificationService->notifyActivity(
                ArabicLocale::trans('messages.notification_project_deleted_title'),
                ArabicLocale::trans('messages.notification_project_deleted_message', ['name' => $event->projectName]),
                [
                    'action' => 'deleted',
                    'project_id' => $event->projectId,
                ],
            );

            return;
        }

        $project = $event->project;
        [$titleKey, $messageKey, $action] = match (true) {
            $event instanceof ProjectCreated => [
                'notification_project_created_title',
                'notification_project_created_message',
                'created',
            ],
            $event instanceof ProjectUpdated => [
                'notification_project_updated_title',
                'notification_project_updated_message',
                'updated',
            ],
            $event instanceof ProjectArchived => [
                'notification_project_archived_title',
                'notification_project_archived_message',
                'archived',
            ],
            $event instanceof ProjectRestored => [
                'notification_project_restored_title',
                'notification_project_restored_message',
                'restored',
            ],
        };

        $this->notificationService->notifyActivity(
            ArabicLocale::trans('messages.'.$titleKey),
            ArabicLocale::trans('messages.'.$messageKey, ['name' => $project->name]),
            [
                'action' => $action,
                'project_id' => $project->id,
            ],
            $project,
        );
    }
}
