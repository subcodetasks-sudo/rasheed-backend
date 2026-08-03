<?php

namespace Modules\Inventory\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Http\Resources\InventoryMovementResource;
use Modules\Inventory\Models\InventoryMovement;

class InventoryStockMoved implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly InventoryMovement $movement,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('inventory'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.stock-moved';
    }

    public function broadcastWith(): array
    {
        return [
            'movement' => (new InventoryMovementResource($this->movement))->resolve(),
        ];
    }
}
