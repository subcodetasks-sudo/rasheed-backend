<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\RestoreProjectWorkflow;

class RestoreProjectController extends Controller
{
    public function __invoke(Project $project, RestoreProjectWorkflow $workflow): JsonResponse
    {
        $restored = $workflow->handle($project);

        return $this->successResponse(
            __('messages.project_restored_successfully'),
            new ProjectResource($restored)
        );
    }
}
