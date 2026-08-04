<?php

namespace Modules\Inventory\Queries;

use App\Support\Query\BaseQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Models\InventoryItem;

class ListInventoryItemsQuery
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = InventoryItem::query()->with(['project', 'inventoryCategory']);
        $perPage = (int) $request->input('per_page', 15);

        $categoryId = $request->input('filter.category_id', $request->input('filter.inventory_category_id'));
        if ($categoryId !== null && $categoryId !== '') {
            $query->where('inventory_category_id', $categoryId);
        }

        $base = new BaseQueryService($query, $request);
        $base->allowedFilters(['project_id'])
            ->allowedSorts(['name', 'code', 'inventory_category_id', 'created_at', 'current_balance'])
            ->allowedSearch(['name', 'code'])
            ->apply();

        if (! $request->filled('sort')) {
            $query->orderByDesc('created_at');
        }

        return $query->paginate($perPage);
    }
}
