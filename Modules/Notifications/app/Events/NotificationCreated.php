<?php

namespace Modules\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Notifications\Models\Notification;

class NotificationCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Notification $notification,
    ) {}
}
