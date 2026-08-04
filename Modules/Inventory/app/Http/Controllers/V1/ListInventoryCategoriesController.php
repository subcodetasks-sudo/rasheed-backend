<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Resources\InventoryCategoryResource;
use Modules\Inventory\Workflows\Category\ListInventoryCategoriesWorkflow;

class ListInventoryCategoriesController extends Controller
{
    public function __invoke(ListInventoryCategoriesWorkflow $workflow): JsonResponse
    {
        $categories = $workflow->handle();

        return $this->successResponse(
            __('messages.inventory_categories_fetched_successfully'),
            InventoryCategoryResource::collection($categories)
        );
    }
}
