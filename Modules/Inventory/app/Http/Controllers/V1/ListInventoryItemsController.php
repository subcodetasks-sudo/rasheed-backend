<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\ListInventoryItemsRequest;
use Modules\Inventory\Http\Resources\InventoryItemResource;
use Modules\Inventory\Workflows\ListInventoryItemsWorkflow;

class ListInventoryItemsController extends Controller
{
    public function __invoke(ListInventoryItemsRequest $request, ListInventoryItemsWorkflow $workflow): JsonResponse
    {
        $result = $workflow->handle($request);

        return $this->successResponse(
            __('messages.inventory_items_fetched_successfully'),
            InventoryItemResource::collection(collect($result['items']->items())),
            extra: array_merge(
                $this->paginationPayload($result['items']),
                ['summary' => $result['summary']],
            ),
        );
    }
}
