<?php

namespace Modules\Notifications\Actions;

use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Support\NotificationPageTypeMapper;

class GetNotificationStatisticsAction
{
    /**
     * @return array{total: int, urgent: int, notification: int, information: int}
     */
    public function execute(): array
    {
        $row = Notification::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as urgent', [NotificationType::Danger->value])
            ->selectRaw(
                'SUM(CASE WHEN type IN (?, ?, ?) THEN 1 ELSE 0 END) as notification_count',
                [
                    NotificationType::Activity->value,
                    NotificationType::Success->value,
                    NotificationType::Warning->value,
                ]
            )
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as information', [NotificationType::Info->value])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            NotificationPageTypeMapper::URGENT => (int) ($row->urgent ?? 0),
            NotificationPageTypeMapper::NOTIFICATION => (int) ($row->notification_count ?? 0),
            NotificationPageTypeMapper::INFORMATION => (int) ($row->information ?? 0),
        ];
    }
}
