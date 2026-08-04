<?php

namespace Modules\Inventory\DTOs;

readonly class CreateInventoryItemData
{
    public function __construct(
        public string $name,
        public int $categoryId,
        public int $projectId,
        public string $unit,
        public float $openingPrice,
        public float $openingQuantity,
        public float $minimumStockLevel,
        public ?string $notes,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            categoryId: (int) $data['category_id'],
            projectId: (int) $data['project_id'],
            unit: (string) $data['unit'],
            openingPrice: (float) $data['opening_price'],
            openingQuantity: (float) $data['opening_quantity'],
            minimumStockLevel: (float) ($data['minimum_stock_level'] ?? 0),
            notes: $data['notes'] ?? null,
        );
    }
}
