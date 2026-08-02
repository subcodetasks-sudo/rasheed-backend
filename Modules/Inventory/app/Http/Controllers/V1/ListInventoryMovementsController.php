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
        $result = $workflow->handle($request);

        return $this->successResponse(
            __('messages.inventory_movements_fetched_successfully'),
            InventoryMovementResource::collection(collect($result['movements']->items())),
            extra: array_merge(
                $this->paginationPayload($result['movements']),
                ['summary' => $result['summary']],
            ),
        );
    }
}
