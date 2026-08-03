<?php

namespace Modules\CashStation\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashStationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel(sprintf('cash-station.%d-%02d', $this->year, $this->month)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cash-station.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
