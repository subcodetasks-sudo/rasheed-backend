<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\DTOs\OutgoingStockData;
use Modules\Inventory\Http\Requests\CreateOutgoingStockRequest;
use Modules\Inventory\Http\Resources\InventoryMovementResource;
use Modules\Inventory\Workflows\CreateOutgoingStockWorkflow;

class CreateOutgoingStockController extends Controller
{
    public function __invoke(CreateOutgoingStockRequest $request, CreateOutgoingStockWorkflow $workflow): JsonResponse
    {
        $movement = $workflow->handle(OutgoingStockData::fromArray($request->validated()));

        return $this->successResponse(
            __('messages.inventory_outgoing_created_successfully'),
            new InventoryMovementResource($movement),
            201
        );
    }
}
