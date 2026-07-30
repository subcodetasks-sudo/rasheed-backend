<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\ShowProjectWorkflow;

class ShowProjectController extends Controller
{
    public function __invoke(Project $project, ShowProjectWorkflow $workflow): JsonResponse
    {
        $project = $workflow->handle($project);

        return $this->successResponse(
            __('messages.project_fetched_successfully'),
            new ProjectResource($project)
        );
    }
}
