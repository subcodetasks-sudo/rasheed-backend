<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Http\Requests\StoreProjectRequest;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Workflows\Project\CreateProjectWorkflow;

class StoreProjectController extends Controller
{
    public function __invoke(StoreProjectRequest $request, CreateProjectWorkflow $workflow): JsonResponse
    {
        $project = $workflow->handle(ProjectData::fromArray($request->validated()));

        return $this->successResponse(
            __('messages.project_created_successfully'),
            new ProjectResource($project),
            201
        );
    }
}
