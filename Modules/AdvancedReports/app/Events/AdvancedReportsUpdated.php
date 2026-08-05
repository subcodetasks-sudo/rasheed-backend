<?php

namespace Modules\AdvancedReports\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvancedReportsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $affectedDate,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('advanced-reports'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'advanced-reports.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'affected_date' => $this->affectedDate,
        ];
    }
}
