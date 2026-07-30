<?php

namespace Modules\Inventory\Queries;

use App\Support\Query\BaseQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Models\InventoryMovement;

class ListInventoryMovementsQuery
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = InventoryMovement::query()->with(['item', 'beneficiaryProject', 'consumptions']);
        $perPage = (int) $request->input('per_page', 15);

        $base = new BaseQueryService($query, $request);
        $base->allowedFilters(['inventory_item_id', 'type', 'expense_type', 'beneficiary_project_id'])
            ->allowedSorts(['movement_date', 'created_at', 'quantity'])
            ->allowedSearch([])
            ->apply();

        if (! $request->filled('sort')) {
            $query->orderByDesc('created_at');
        }

        return $query->paginate($perPage);
    }
}
