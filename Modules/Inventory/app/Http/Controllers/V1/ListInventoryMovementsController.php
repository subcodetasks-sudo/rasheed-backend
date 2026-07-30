<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\ListInventoryMovementsRequest;
use Modules\Inventory\Http\Resources\InventoryMovementResource;
use Modules\Inventory\Workflows\ListInventoryMovementsWorkflow;

class ListInventoryMovementsController extends Controller
{
    public function __invoke(ListInventoryMovementsRequest $request, ListInventoryMovementsWorkflow $workflow): JsonResponse
    {
        $movements = $workflow->handle($request);

        return $this->paginated($movements, InventoryMovementResource::class, __('messages.inventory_movements_fetched_successfully'));
    }
}
