<?php

namespace Modules\OperationalFund\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperationalFundUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel(sprintf('operational-fund.%d-%02d', $this->year, $this->month)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'operational-fund.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
