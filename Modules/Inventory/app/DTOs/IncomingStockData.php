<?php

namespace Modules\Inventory\DTOs;

readonly class IncomingStockData
{
    public function __construct(
        public int $inventoryItemId,
        public float $quantity,
        public float $unitPrice,
        public ?string $notes,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            inventoryItemId: (int) $data['inventory_item_id'],
            quantity: (float) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
            notes: $data['notes'] ?? null,
        );
    }
}
