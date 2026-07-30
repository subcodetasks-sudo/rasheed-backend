<?php

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\InventoryExpenseType;

readonly class OutgoingStockData
{
    public function __construct(
        public int $inventoryItemId,
        public float $quantity,
        public int $beneficiaryProjectId,
        public InventoryExpenseType $expenseType,
        public ?string $notes,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            inventoryItemId: (int) $data['inventory_item_id'],
            quantity: (float) $data['quantity'],
            beneficiaryProjectId: (int) $data['beneficiary_project_id'],
            expenseType: InventoryExpenseType::from($data['expense_type']),
            notes: $data['notes'] ?? null,
        );
    }
}
