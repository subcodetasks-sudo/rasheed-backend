<?php

namespace Modules\AdvancedReports\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AdvancedReports\Http\Requests\ShowAdvancedReportsRequest;
use Modules\AdvancedReports\Http\Resources\AdvancedReportsResource;
use Modules\AdvancedReports\Workflows\ShowAdvancedReportsWorkflow;

class ShowAdvancedReportsController extends Controller
{
    public function __invoke(
        ShowAdvancedReportsRequest $request,
        ShowAdvancedReportsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.advanced_reports_fetched_successfully'),
            new AdvancedReportsResource(
                $workflow->handle($request->reportType(), $request->period()),
            ),
        );
    }
}
