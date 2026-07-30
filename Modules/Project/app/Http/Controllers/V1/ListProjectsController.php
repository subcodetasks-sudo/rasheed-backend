<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Requests\FilterProjectsRequest;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Workflows\Project\ListProjectsWorkflow;

class ListProjectsController extends Controller
{
    public function __invoke(FilterProjectsRequest $request, ListProjectsWorkflow $workflow): JsonResponse
    {
        $projects = $workflow->handle($request);

        return $this->paginated($projects, ProjectResource::class, __('messages.projects_fetched_successfully'));
    }
}
