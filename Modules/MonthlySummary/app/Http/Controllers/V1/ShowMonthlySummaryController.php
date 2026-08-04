<?php

namespace Modules\MonthlySummary\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MonthlySummary\Http\Requests\ShowMonthlySummaryRequest;
use Modules\MonthlySummary\Http\Resources\MonthlySummaryResource;
use Modules\MonthlySummary\Workflows\ShowMonthlySummaryWorkflow;

class ShowMonthlySummaryController extends Controller
{
    public function __invoke(
        ShowMonthlySummaryRequest $request,
        ShowMonthlySummaryWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.monthly_summary_fetched_successfully'),
            new MonthlySummaryResource($workflow->handle($request->month(), $request->year())),
        );
    }
}
