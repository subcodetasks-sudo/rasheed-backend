<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Requests\CalculateProjectDeductionRequest;
use Modules\Project\Models\Project;
use Modules\Project\Workflows\Project\CalculateProjectDeductionWorkflow;

class CalculateProjectDeductionController extends Controller
{
    public function __invoke(
        CalculateProjectDeductionRequest $request,
        Project $project,
        CalculateProjectDeductionWorkflow $workflow
    ): JsonResponse {
        $validated = $request->validated();

        $result = $workflow->handle(
            $project,
            (float) $validated['income'],
            $validated['relative_incomes'] ?? []
        );

        return $this->successResponse(__('messages.deductions_calculated_successfully'), $result);
    }
}
