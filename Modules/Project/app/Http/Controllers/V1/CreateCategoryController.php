<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Requests\StoreCategoryRequest;
use Modules\Project\Http\Resources\CategoryResource;
use Modules\Project\Workflows\Category\CreateCategoryWorkflow;

class CreateCategoryController extends Controller
{
    public function __invoke(StoreCategoryRequest $request, CreateCategoryWorkflow $workflow): JsonResponse
    {
        $category = $workflow->handle($request->validated());

        return $this->successResponse(
            __('messages.category_created_successfully'),
            new CategoryResource($category),
            201
        );
    }
}
