<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Http\Requests\UpdateProjectRequest;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\UpdateProjectWorkflow;

class UpdateProjectController extends Controller
{
    public function __invoke(
        UpdateProjectRequest $request,
        Project $project,
        UpdateProjectWorkflow $workflow
    ): JsonResponse {
        $updated = $workflow->handle($project, ProjectData::fromArray($request->validated()));

        return $this->successResponse(
            __('messages.project_updated_successfully'),
            new ProjectResource($updated)
        );
    }
}
