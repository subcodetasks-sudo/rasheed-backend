<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Queries\ListInventoryItemsQuery;

class ListInventoryItemsWorkflow
{
    public function __construct(
        private readonly ListInventoryItemsQuery $listInventoryItemsQuery,
    ) {}

    public function handle(Request $request): LengthAwarePaginator
    {
        return $this->listInventoryItemsQuery->paginate($request);
    }
}
