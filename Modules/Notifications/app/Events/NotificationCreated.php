<?php

namespace Modules\Notifications\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Notifications\Models\Notification;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Notification $notification,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type?->value,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'meta' => $this->notification->meta ?? [],
            'subject_type' => $this->notification->subject_type,
            'subject_id' => $this->notification->subject_id,
            'created_at' => $this->notification->created_at?->toISOString(),
        ];
    }
}
