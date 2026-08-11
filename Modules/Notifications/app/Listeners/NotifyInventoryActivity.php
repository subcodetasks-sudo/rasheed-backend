<?php

namespace Modules\Notifications\Listeners;

use Modules\Inventory\Events\InventoryCategoryCreated;
use Modules\Inventory\Events\InventoryCategoryDeleted;
use Modules\Inventory\Events\InventoryCategoryUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryItemDeleted;
use Modules\Inventory\Events\InventoryMovementDeleted;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Notifications\Services\NotificationService;

class NotifyInventoryActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(
        InventoryItemCreated|InventoryItemDeleted|InventoryStockMoved|InventoryMovementDeleted|InventoryCategoryCreated|InventoryCategoryUpdated|InventoryCategoryDeleted $event,
    ): void {
        if ($event instanceof InventoryItemDeleted) {
            $this->notificationService->notifyActivity(
                __('messages.notification_inventory_item_deleted_title'),
                __('messages.notification_inventory_item_deleted_message', [
                    'name' => $event->itemName,
                    'code' => $event->itemCode,
                ]),
                [
                    'action' => 'item_deleted',
                    'inventory_item_id' => $event->itemId,
                ],
            );

            return;
        }

        if ($event instanceof InventoryMovementDeleted) {
            $this->notificationService->notifyActivity(
                __('messages.notification_inventory_movement_deleted_title'),
                __('messages.notification_inventory_movement_deleted_message', [
                    'type' => $event->type->value,
                    'quantity' => $event->quantity,
                    'name' => $event->itemName,
                ]),
                [
                    'action' => 'movement_deleted',
                    'movement_id' => $event->movementId,
                    'inventory_item_id' => $event->inventoryItemId,
                    'type' => $event->type->value,
                ],
            );

            return;
        }

        if ($event instanceof InventoryCategoryDeleted) {
            $this->notificationService->notifyActivity(
                __('messages.notification_inventory_category_deleted_title'),
                __('messages.notification_inventory_category_deleted_message', ['name' => $event->categoryName]),
                [
                    'action' => 'category_deleted',
                    'inventory_category_id' => $event->categoryId,
                ],
            );

            return;
        }

        if ($event instanceof InventoryCategoryCreated || $event instanceof InventoryCategoryUpdated) {
            $category = $event->category;
            [$titleKey, $messageKey, $action] = $event instanceof InventoryCategoryCreated
                ? ['notification_inventory_category_created_title', 'notification_inventory_category_created_message', 'category_created']
                : ['notification_inventory_category_updated_title', 'notification_inventory_category_updated_message', 'category_updated'];

            $this->notificationService->notifyActivity(
                __('messages.'.$titleKey),
                __('messages.'.$messageKey, ['name' => $category->name]),
                [
                    'action' => $action,
                    'inventory_category_id' => $category->id,
                ],
                $category,
            );

            return;
        }

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
