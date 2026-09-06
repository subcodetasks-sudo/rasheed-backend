<?php

namespace Modules\Inventory\Actions;

use Modules\Inventory\Models\InventoryItem;

class BuildInventoryLowStockSummaryAction
{
    /**
     * Items at or below their minimum stock level, independent of any list
     * filter/search — this feeds the global "low / out of stock" indicator on
     * the inventory management screen. `depleted` items (balance <= 0) are a
     * subset of the same list, surfaced separately so the UI can label them.
     *
     * @return array{
     *     low_stock_count: int,
     *     depleted_count: int,
     *     low_stock_items: array<int, array{
     *         id: int, code: string|null, name: string,
     *         current_balance: string, minimum_stock_level: string,
     *         project_id: int|null, depleted: bool
     *     }>
     * }
     */
    public function execute(): array
    {
        $items = InventoryItem::query()
            ->whereColumn('current_balance', '<=', 'minimum_stock_level')
            ->orderBy('code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'current_balance', 'minimum_stock_level', 'project_id'])
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'current_balance' => $item->current_balance,
                'minimum_stock_level' => $item->minimum_stock_level,
                'project_id' => $item->project_id,
                'depleted' => (float) $item->current_balance <= 0.0,
            ])
            ->values();

        return [
            'low_stock_count' => $items->count(),
            'depleted_count' => $items->where('depleted', true)->count(),
            'low_stock_items' => $items->all(),
        ];
    }
}
