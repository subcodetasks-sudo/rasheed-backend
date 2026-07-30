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
        $items = $workflow->handle($request);

        return $this->paginated($items, InventoryItemResource::class, __('messages.inventory_items_fetched_successfully'));
    }
}
