<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Models\Category;
use Modules\Project\Workflows\Category\DeleteCategoryWorkflow;

class DeleteCategoryController extends Controller
{
    public function __invoke(Category $category, DeleteCategoryWorkflow $workflow): JsonResponse
    {
        $workflow->handle($category);

        return $this->successResponse(__('messages.category_deleted_successfully'));
    }
}
