<?php

namespace Modules\Notifications\Actions;

use Illuminate\Support\Facades\DB;
use Modules\DailyJournal\Actions\ReadAccumulatedAdministrativeDebtTipAction;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Events\NotificationCreated;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Services\NotificationSseService;
use Modules\Project\Models\Project;

class SyncAdministrativeDebtAlertAction
{
    public const META_ACTION = 'administrative_debt_alert';

    public function __construct(
        private readonly ReadAccumulatedAdministrativeDebtTipAction $readAccumulatedAdministrativeDebtTipAction,
    ) {}

    public function execute(int $projectId, ?string $asOfDate = null): void
    {
        if ($projectId <= 0) {
            return;
        }

        $asOfDate ??= now()->toDateString();

        $project = Project::query()->withTrashed()->find($projectId);
        if ($project === null) {
            $this->deleteAlert($projectId);

            return;
        }

        $debts = $this->readAccumulatedAdministrativeDebtTipAction->execute([$projectId], $asOfDate);
        $remaining = round((float) ($debts[$projectId] ?? 0), 2);

        if ($remaining <= 0) {
            $this->deleteAlert($projectId);

            return;
        }

        $title = __('messages.notification_administrative_debt_alert_title');
        $message = __('messages.notification_administrative_debt_alert_message', [
            'name' => $project->name,
            'amount' => number_format($remaining, 2, '.', ','),
        ]);
        $meta = [
            'action' => self::META_ACTION,
            'project_id' => $projectId,
            'remaining_debt' => $remaining,
        ];

        DB::transaction(function () use ($project, $projectId, $title, $message, $meta, $remaining): void {
            $existing = $this->findAlert($projectId, lock: true);

            if ($existing === null) {
                $notification = Notification::query()->create([
                    'type' => NotificationType::Warning,
                    'title' => $title,
                    'message' => $message,
                    'meta' => $meta,
                    'subject_type' => Project::class,
                    'subject_id' => $project->getKey(),
                    'project_id' => $projectId,
                ]);

                $this->announceAfterCommit($notification);

                return;
            }

            $previousRemaining = round((float) ($existing->meta['remaining_debt'] ?? 0), 2);

            if ($previousRemaining === $remaining && $existing->title === $title && $existing->message === $message) {
                return;
            }

            $existing->fill([
                'type' => NotificationType::Warning,
                'title' => $title,
                'message' => $message,
                'meta' => array_merge(is_array($existing->meta) ? $existing->meta : [], $meta),
                'subject_type' => Project::class,
                'subject_id' => $project->getKey(),
                'project_id' => $projectId,
            ]);
            $existing->save();

            $this->announceAfterCommit($existing);
        });
    }

    /**
     * @param  list<int>  $projectIds
     */
    public function executeMany(array $projectIds, ?string $asOfDate = null): void
    {
        foreach (array_unique(array_map('intval', $projectIds)) as $projectId) {
            if ($projectId > 0) {
                $this->execute($projectId, $asOfDate);
            }
        }
    }

    protected function deleteAlert(int $projectId): void
    {
        $alert = $this->findAlert($projectId, lock: false);
        $alert?->delete();
    }

    protected function findAlert(int $projectId, bool $lock): ?Notification
    {
        $query = Notification::query()
            ->where('project_id', $projectId)
            ->where('type', NotificationType::Warning->value);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->first(fn (Notification $notification) => ($notification->meta['action'] ?? null) === self::META_ACTION);
    }

    protected function announceAfterCommit(Notification $notification): void
    {
        DB::afterCommit(function () use ($notification): void {
            NotificationSseService::announce((int) $notification->id);
            NotificationCreated::dispatch($notification);
        });
    }
}
