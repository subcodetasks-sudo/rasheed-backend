<?php

namespace Modules\Notifications\Listeners;

use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Notifications\Services\NotificationService;

class NotifyInventoryActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(InventoryItemCreated|InventoryStockMoved $event): void
    {
        if ($event instanceof InventoryItemCreated) {
            $item = $event->item;

            $this->notificationService->notifyActivity(
                __('messages.notification_inventory_item_created_title'),
                __('messages.notification_inventory_item_created_message', [
                    'name' => $item->name,
                    'code' => $item->code,
                ]),
                [
                    'action' => 'item_created',
                    'inventory_item_id' => $item->id,
                ],
                $item,
            );

            return;
        }

        $movement = $event->movement;
        $item = $movement->relationLoaded('item') ? $movement->item : $movement->item()->first();

        $this->notificationService->notifyActivity(
            __('messages.notification_inventory_stock_moved_title'),
            __('messages.notification_inventory_stock_moved_message', [
                'type' => $movement->type?->value ?? 'movement',
                'quantity' => $movement->quantity,
                'name' => $item?->name ?? '#'.$movement->inventory_item_id,
            ]),
            [
                'action' => 'stock_moved',
                'movement_id' => $movement->id,
                'inventory_item_id' => $movement->inventory_item_id,
                'type' => $movement->type?->value,
            ],
            $movement,
        );
    }
}
