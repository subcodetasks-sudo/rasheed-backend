<?php

namespace Modules\Inventory\Queries;

use App\Support\Query\BaseQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Inventory\Models\InventoryMovement;

class ListInventoryMovementsQuery
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = $this->filteredQuery($request);
        $perPage = (int) $request->input('per_page', 15);

        $this->applyDefaultSort($query, $request);

        return $query->paginate($perPage);
    }

    /**
     * Same filter set as the paginated list (for summary aggregates).
     */
    public function filteredQuery(Request $request): Builder
    {
        $query = InventoryMovement::query()->with(['item', 'beneficiaryProject', 'consumptions']);

        $this->applyDateRangeFilters($query, $request);

        $base = new BaseQueryService($query, $request);
        $base->allowedFilters(['inventory_item_id', 'type', 'expense_type', 'beneficiary_project_id'])
            ->allowedSorts(['movement_date', 'created_at', 'quantity'])
            ->allowedSearch([])
            ->apply();

        return $query;
    }

    private function applyDateRangeFilters(Builder $query, Request $request): void
    {
        $from = $request->input('filter.movement_date_from');
        $to = $request->input('filter.movement_date_to');

        if ($from) {
            $query->whereDate('movement_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('movement_date', '<=', $to);
        }
    }

    private function applyDefaultSort(Builder $query, Request $request): void
    {
        if ($request->filled('sort')) {
            return;
        }

        $query->orderByDesc('movement_date')->orderByDesc('created_at');
    }
}
