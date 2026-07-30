<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Queries\ListInventoryMovementsQuery;

class ListInventoryMovementsWorkflow
{
    public function __construct(
        private readonly ListInventoryMovementsQuery $listInventoryMovementsQuery,
    ) {}

    public function handle(Request $request): LengthAwarePaginator
    {
        return $this->listInventoryMovementsQuery->paginate($request);
    }
}
