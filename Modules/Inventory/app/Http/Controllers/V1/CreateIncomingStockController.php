<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\DTOs\IncomingStockData;
use Modules\Inventory\Http\Requests\CreateIncomingStockRequest;
use Modules\Inventory\Http\Resources\InventoryMovementResource;
use Modules\Inventory\Workflows\CreateIncomingStockWorkflow;

class CreateIncomingStockController extends Controller
{
    public function __invoke(CreateIncomingStockRequest $request, CreateIncomingStockWorkflow $workflow): JsonResponse
    {
        $movement = $workflow->handle(IncomingStockData::fromArray($request->validated()));

        return $this->successResponse(
            __('messages.inventory_incoming_created_successfully'),
            new InventoryMovementResource($movement),
            201
        );
    }
}
