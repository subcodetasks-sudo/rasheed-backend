<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Resources\InventoryItemResource;
use Modules\Inventory\Workflows\DeleteInventoryMovementWorkflow;

class DeleteInventoryMovementController extends Controller
{
    public function __invoke(int $movement, DeleteInventoryMovementWorkflow $workflow): JsonResponse
    {
        $item = $workflow->handle($movement, auth()->user()?->uuid);

        return $this->successResponse(
            __('messages.inventory_movement_deleted_successfully'),
            new InventoryItemResource($item),
        );
    }
}
