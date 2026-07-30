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
        $query = InventoryItem::query()->with(['project']);
        $perPage = (int) $request->input('per_page', 15);

        $base = new BaseQueryService($query, $request);
        $base->allowedFilters(['category', 'project_id'])
            ->allowedSorts(['name', 'code', 'category', 'created_at', 'current_balance'])
            ->allowedSearch(['name', 'code'])
            ->apply();

        if (! $request->filled('sort')) {
            $query->orderByDesc('created_at');
        }

        return $query->paginate($perPage);
    }
}
