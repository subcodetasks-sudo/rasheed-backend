<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Workflows\DeleteInventoryItemWorkflow;

class DeleteInventoryItemController extends Controller
{
    public function __invoke(int $item, DeleteInventoryItemWorkflow $workflow): JsonResponse
    {
        $workflow->handle($item);

        return $this->successResponse(__('messages.inventory_item_deleted_successfully'));
    }
}
