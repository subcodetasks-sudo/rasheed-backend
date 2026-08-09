<?php

namespace Modules\Notifications\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Events\NotificationCreated;
use Modules\Notifications\Models\Notification;
use Modules\Project\Models\Project;

class NotificationService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyActivity(string $title, string $message, array $meta = [], ?Model $subject = null): Notification
    {
        return $this->createAndBroadcast(NotificationType::Activity, $title, $message, $meta, $subject);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifySuccess(string $title, string $message, array $meta = [], ?Model $subject = null): Notification
    {
        return $this->createAndBroadcast(NotificationType::Success, $title, $message, $meta, $subject);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyWarning(string $title, string $message, array $meta = [], ?Model $subject = null): Notification
    {
        return $this->createAndBroadcast(NotificationType::Warning, $title, $message, $meta, $subject);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyDanger(string $title, string $message, array $meta = [], ?Model $subject = null): Notification
    {
        return $this->createAndBroadcast(NotificationType::Danger, $title, $message, $meta, $subject);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyInfo(string $title, string $message, array $meta = [], ?Model $subject = null): Notification
    {
        return $this->createAndBroadcast(NotificationType::Info, $title, $message, $meta, $subject);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function createAndBroadcast(
        NotificationType $type,
        string $title,
        string $message,
        array $meta = [],
        ?Model $subject = null,
    ): Notification {
        $notification = Notification::query()->create([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'meta' => $meta,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'project_id' => $this->resolveProjectId($meta, $subject),
        ]);

        NotificationSseService::announce((int) $notification->id);
        NotificationCreated::dispatch($notification);

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveProjectId(array $meta, ?Model $subject): ?int
    {
        if (isset($meta['project_id']) && is_numeric($meta['project_id'])) {
            $projectId = (int) $meta['project_id'];

            return $projectId > 0 ? $projectId : null;
        }

        if ($subject instanceof Project) {
            return (int) $subject->getKey();
        }

        return null;
    }
}
