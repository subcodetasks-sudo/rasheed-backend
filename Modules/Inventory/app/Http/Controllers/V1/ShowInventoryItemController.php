<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Resources\InventoryItemResource;
use Modules\Inventory\Workflows\ShowInventoryItemWorkflow;

class ShowInventoryItemController extends Controller
{
    public function __invoke(int $item, ShowInventoryItemWorkflow $workflow): JsonResponse
    {
        $model = $workflow->handle($item);

        return $this->successResponse(
            __('messages.inventory_item_fetched_successfully'),
            new InventoryItemResource($model),
        );
    }
}
