<?php

namespace Modules\Inventory\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Http\Resources\InventoryItemResource;
use Modules\Inventory\Models\InventoryItem;

class InventoryItemCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly InventoryItem $item,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('inventory'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.item-created';
    }

    public function broadcastWith(): array
    {
        return [
            'item' => (new InventoryItemResource($this->item))->resolve(),
        ];
    }
}
