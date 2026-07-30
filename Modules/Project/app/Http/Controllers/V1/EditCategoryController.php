<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Requests\UpdateCategoryRequest;
use Modules\Project\Http\Resources\CategoryResource;
use Modules\Project\Models\Category;
use Modules\Project\Workflows\Category\UpdateCategoryWorkflow;

class EditCategoryController extends Controller
{
    public function __invoke(
        UpdateCategoryRequest $request,
        Category $category,
        UpdateCategoryWorkflow $workflow
    ): JsonResponse {
        $updated = $workflow->handle($category, $request->validated());

        return $this->successResponse(
            __('messages.category_updated_successfully'),
            new CategoryResource($updated)
        );
    }
}
