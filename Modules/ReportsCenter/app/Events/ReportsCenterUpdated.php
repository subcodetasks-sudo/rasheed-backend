<?php

namespace Modules\ReportsCenter\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportsCenterUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $affectedDate,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('reports-center'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reports-center.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'affected_date' => $this->affectedDate,
        ];
    }
}
