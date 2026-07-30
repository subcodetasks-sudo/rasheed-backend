<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\DTOs\CreateInventoryItemData;
use Modules\Inventory\Http\Requests\CreateInventoryItemRequest;
use Modules\Inventory\Http\Resources\InventoryItemResource;
use Modules\Inventory\Workflows\CreateInventoryItemWorkflow;

class CreateInventoryItemController extends Controller
{
    public function __invoke(CreateInventoryItemRequest $request, CreateInventoryItemWorkflow $workflow): JsonResponse
    {
        $item = $workflow->handle(CreateInventoryItemData::fromArray($request->validated()));

        return $this->successResponse(
            __('messages.inventory_item_created_successfully'),
            new InventoryItemResource($item),
            201
        );
    }
}
