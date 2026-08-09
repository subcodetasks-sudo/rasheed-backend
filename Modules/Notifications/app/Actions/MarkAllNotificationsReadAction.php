<?php

namespace Modules\Notifications\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Models\NotificationRead;
use Modules\User\app\Models\User;

class MarkAllNotificationsReadAction
{
    /**
     * @return array{marked: int}
     */
    public function execute(User $user): array
    {
        $userId = $user->uuid;
        $now = now();

        $unreadIds = Notification::query()
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $userId))
            ->pluck('id');

        if ($unreadIds->isEmpty()) {
            return ['marked' => 0];
        }

        $rows = $unreadIds->map(fn ($id) => [
            'notification_id' => $id,
            'user_id' => $userId,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                NotificationRead::query()->insert($chunk);
            }
        });

        return ['marked' => count($rows)];
    }
}
