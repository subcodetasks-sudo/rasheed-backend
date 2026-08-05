<?php

namespace Modules\ReportsCenter\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ReportsCenter\Http\Requests\ShowReportsCenterRequest;
use Modules\ReportsCenter\Http\Resources\ReportsCenterResource;
use Modules\ReportsCenter\Workflows\ShowReportsCenterWorkflow;

class ShowReportsCenterController extends Controller
{
    public function __invoke(
        ShowReportsCenterRequest $request,
        ShowReportsCenterWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.reports_center_fetched_successfully'),
            new ReportsCenterResource($workflow->handle($request->period())),
        );
    }
}
