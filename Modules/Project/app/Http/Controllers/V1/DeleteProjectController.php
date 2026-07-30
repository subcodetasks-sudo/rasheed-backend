<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\DeleteProjectWorkflow;

class DeleteProjectController extends Controller
{
    public function __invoke(Project $project, DeleteProjectWorkflow $workflow): JsonResponse
    {
        $workflow->handle($project);

        return $this->successResponse(__('messages.project_deleted_successfully'));
    }
}
