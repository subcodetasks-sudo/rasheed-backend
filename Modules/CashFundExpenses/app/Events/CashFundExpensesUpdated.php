<?php

namespace Modules\CashFundExpenses\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashFundExpensesUpdated implements ShouldBroadcastNow
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
            new Channel(sprintf('cash-fund-expenses.%d-%02d', $this->year, $this->month)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cash-fund-expenses.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
