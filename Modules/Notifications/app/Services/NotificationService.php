<?php

namespace Modules\Notifications\Services;

use App\Support\ArabicLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $meta = $this->withActorMeta($meta);
        $message = $this->appendActorToMessage($message, $meta);

        $notification = Notification::query()->create([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'meta' => $meta,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'project_id' => $this->resolveProjectId($meta, $subject),
        ]);

        // Listeners often run inside workflow DB::transaction(); publish only after commit
        // so SSE clients never see rows that later roll back.
        DB::afterCommit(function () use ($notification): void {
            NotificationSseService::announce((int) $notification->id);
            NotificationCreated::dispatch($notification);
        });

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function withActorMeta(array $meta): array
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user() ?? request()->user();

        if ($user === null) {
            return $meta;
        }

        if (! array_key_exists('actor_id', $meta)) {
            $meta['actor_id'] = $user->uuid ?? $user->getAuthIdentifier();
        }

        if (! array_key_exists('actor_name', $meta)) {
            $meta['actor_name'] = (string) ($user->full_name ?? '');
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function appendActorToMessage(string $message, array $meta): string
    {
        $actorName = trim((string) ($meta['actor_name'] ?? ''));

        if ($actorName === '') {
            return $message;
        }

        $suffix = ArabicLocale::trans('messages.notification_by_actor', ['name' => $actorName]);
        $trimmed = rtrim($message);

        if (str_ends_with($trimmed, '.')) {
            return substr($trimmed, 0, -1).' '.$suffix.'.';
        }

        return $trimmed.' '.$suffix;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveProjectId(array $meta, ?Model $subject): ?int
    {
        if (isset($meta['project_id']) && is_numeric($meta['project_id'])) {
            $projectId = (int) $meta['project_id'];

            return $this->existingProjectId($projectId);
        }

        if ($subject instanceof Project) {
            return $this->existingProjectId((int) $subject->getKey());
        }

        return null;
    }

    private function existingProjectId(int $projectId): ?int
    {
        if ($projectId <= 0) {
            return null;
        }

        return Project::query()->whereKey($projectId)->exists() ? $projectId : null;
    }
}
