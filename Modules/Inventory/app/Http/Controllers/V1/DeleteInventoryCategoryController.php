<?php

namespace Modules\Inventory\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Inventory\Workflows\Category\DeleteInventoryCategoryWorkflow;

class DeleteInventoryCategoryController extends Controller
{
    public function __invoke(
        InventoryCategory $category,
        DeleteInventoryCategoryWorkflow $workflow
    ): JsonResponse {
        $workflow->handle($category);

        return $this->successResponse(__('messages.inventory_category_deleted_successfully'));
    }
}
