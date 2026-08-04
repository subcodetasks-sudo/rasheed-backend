<?php

namespace Modules\MonthlySummary\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MonthlySummary\Http\Requests\ShowMonthlySummaryRequest;
use Modules\MonthlySummary\Workflows\ListContributorOptionsWorkflow;

class ListContributorOptionsController extends Controller
{
    public function __invoke(
        ShowMonthlySummaryRequest $request,
        ListContributorOptionsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.monthly_summary_contributor_options_fetched_successfully'),
            $workflow->handle($request->month(), $request->year()),
        );
    }
}
