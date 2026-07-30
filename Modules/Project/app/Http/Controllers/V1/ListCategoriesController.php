<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Resources\CategoryResource;
use Modules\Project\Workflows\Category\ListCategoriesWorkflow;

class ListCategoriesController extends Controller
{
    public function __invoke(ListCategoriesWorkflow $workflow): JsonResponse
    {
        $categories = $workflow->handle();

        return $this->successResponse(
            __('messages.categories_fetched_successfully'),
            CategoryResource::collection($categories)
        );
    }
}
