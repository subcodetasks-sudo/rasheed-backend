<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Http\Requests\CalculateDeductionsRequest;
use Modules\Project\Workflows\Project\CalculateDeductionsWorkflow;

class CalculateDeductionsController extends Controller
{
    public function __invoke(
        CalculateDeductionsRequest $request,
        CalculateDeductionsWorkflow $workflow
    ): JsonResponse {
        $results = $workflow->handle($request->validated('incomes'));

        return $this->successResponse(__('messages.deductions_calculated_successfully'), $results);
    }
}
