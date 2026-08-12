<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Events\InventoryCategoryCreated;
use Modules\Inventory\Events\InventoryCategoryDeleted;
use Modules\Inventory\Events\InventoryCategoryUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryItemDeleted;
use Modules\Inventory\Events\InventoryMovementDeleted;
use Modules\Inventory\Events\InventoryStockMoved;

class RecordInventoryAuditLog
{
    use RecordsAuditSafely;

    public function handle(
        InventoryItemCreated|InventoryItemDeleted|InventoryStockMoved|InventoryMovementDeleted|InventoryCategoryCreated|InventoryCategoryUpdated|InventoryCategoryDeleted $event,
    ): void {
        if ($event instanceof InventoryItemDeleted) {
            $this->record(
                AuditAction::Deleted,
                ArabicLocale::trans('messages.audit_inventory_item_deleted', [
                    'name' => $event->itemName,
                    'code' => $event->itemCode,
                ]),
                properties: ['inventory_item_id' => $event->itemId],
            );

            return;
        }

        if ($event instanceof InventoryMovementDeleted) {
            $this->record(
                AuditAction::Deleted,
                ArabicLocale::trans('messages.audit_inventory_movement_deleted', [
                    'type' => ArabicLocale::label($event->type->value),
                    'quantity' => $event->quantity,
                    'name' => $event->itemName,
                ]),
                properties: [
                    'movement_id' => $event->movementId,
                    'inventory_item_id' => $event->inventoryItemId,
                    'type' => $event->type->value,
                ],
            );

            return;
        }

        if ($event instanceof InventoryCategoryDeleted) {
            $this->record(
                AuditAction::Deleted,
                ArabicLocale::trans('messages.audit_inventory_category_deleted', ['name' => $event->categoryName]),
                properties: ['inventory_category_id' => $event->categoryId],
            );

            return;
        }

        if ($event instanceof InventoryCategoryCreated || $event instanceof InventoryCategoryUpdated) {
            $action = $event instanceof InventoryCategoryCreated ? AuditAction::Created : AuditAction::Updated;
            $key = $event instanceof InventoryCategoryCreated
                ? 'audit_inventory_category_created'
                : 'audit_inventory_category_updated';

            $this->record(
                $action,
                ArabicLocale::trans("messages.{$key}", ['name' => $event->category->name]),
                subject: $event->category,
                properties: ['inventory_category_id' => $event->category->id],
            );

            return;
        }

        if ($event instanceof InventoryItemCreated) {
            $item = $event->item;

            $this->record(
                AuditAction::Created,
                ArabicLocale::trans('messages.audit_inventory_item_created', [
                    'name' => $item->name,
                    'code' => $item->code,
                ]),
                subject: $item,
                properties: ['inventory_item_id' => $item->id],
            );

            return;
        }

        $movement = $event->movement;
        $item = $movement->relationLoaded('item') ? $movement->item : $movement->item()->first();
        $action = $movement->type === InventoryMovementType::Outgoing
            ? AuditAction::Outgoing
            : AuditAction::Incoming;

        $this->record(
            $action,
            ArabicLocale::trans("messages.audit_inventory_{$action->value}", [
                'quantity' => $movement->quantity,
                'name' => $item?->name ?? '#'.$movement->inventory_item_id,
            ]),
            subject: $movement,
            properties: [
                'movement_id' => $movement->id,
                'inventory_item_id' => $movement->inventory_item_id,
                'type' => $movement->type?->value,
            ],
        );
    }
}
