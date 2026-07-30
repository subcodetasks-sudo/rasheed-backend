<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Resources\ProjectResource;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\ArchiveProjectWorkflow;

class ArchiveProjectController extends Controller
{
    public function __invoke(Project $project, ArchiveProjectWorkflow $workflow): JsonResponse
    {
        $archived = $workflow->handle($project);

        return $this->successResponse(
            __('messages.project_archived_successfully'),
            new ProjectResource($archived)
        );
    }
}
