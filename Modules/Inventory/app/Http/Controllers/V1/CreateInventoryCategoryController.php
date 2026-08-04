<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\StoreInventoryCategoryRequest;
use Modules\Inventory\Http\Resources\InventoryCategoryResource;
use Modules\Inventory\Workflows\Category\CreateInventoryCategoryWorkflow;

class CreateInventoryCategoryController extends Controller
{
    public function __invoke(
        StoreInventoryCategoryRequest $request,
        CreateInventoryCategoryWorkflow $workflow
    ): JsonResponse {
        $category = $workflow->handle($request->validated());

        return $this->successResponse(
            __('messages.inventory_category_created_successfully'),
            new InventoryCategoryResource($category),
            201
        );
    }
}
