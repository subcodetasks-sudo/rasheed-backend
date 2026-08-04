<?php

namespace Modules\MonthlySummary\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Http\Requests\StoreMonthlySummaryContributionRequest;
use Modules\MonthlySummary\Http\Resources\MonthlySummaryResource;
use Modules\MonthlySummary\Workflows\StoreMonthlySummaryContributionWorkflow;

class StoreMonthlySummaryContributionController extends Controller
{
    public function __invoke(
        StoreMonthlySummaryContributionRequest $request,
        StoreMonthlySummaryContributionWorkflow $workflow,
        BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ): JsonResponse {
        $workflow->handle(
            $request->year(),
            $request->month(),
            $request->fromProjectId(),
            $request->toProjectId(),
            $request->contributionType(),
            $request->amount(),
        );

        return $this->successResponse(
            __('messages.monthly_summary_contribution_created_successfully'),
            new MonthlySummaryResource(
                $buildMonthlySummaryAction->execute($request->month(), $request->year())
            ),
            201,
        );
    }
}
