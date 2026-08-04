<?php

namespace Modules\MonthlySummary\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Http\Resources\MonthlySummaryResource;
use Modules\MonthlySummary\Workflows\CancelMonthlySummaryContributionWorkflow;

class CancelMonthlySummaryContributionController extends Controller
{
    public function __invoke(
        int $settlement,
        CancelMonthlySummaryContributionWorkflow $workflow,
        BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ): JsonResponse {
        $result = $workflow->handle($settlement);

        return $this->successResponse(
            __('messages.monthly_summary_contribution_cancelled_successfully'),
            new MonthlySummaryResource(
                $buildMonthlySummaryAction->execute($result['month'], $result['year'])
            ),
        );
    }
}
