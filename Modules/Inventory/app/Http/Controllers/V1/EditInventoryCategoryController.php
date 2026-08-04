<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\UpdateInventoryCategoryRequest;
use Modules\Inventory\Http\Resources\InventoryCategoryResource;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Inventory\Workflows\Category\UpdateInventoryCategoryWorkflow;

class EditInventoryCategoryController extends Controller
{
    public function __invoke(
        UpdateInventoryCategoryRequest $request,
        InventoryCategory $category,
        UpdateInventoryCategoryWorkflow $workflow
    ): JsonResponse {
        $updated = $workflow->handle($category, $request->validated());

        return $this->successResponse(
            __('messages.inventory_category_updated_successfully'),
            new InventoryCategoryResource($updated)
        );
    }
}
