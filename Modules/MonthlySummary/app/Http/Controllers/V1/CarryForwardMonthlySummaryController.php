<?php

namespace Modules\MonthlySummary\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Http\Requests\CarryForwardMonthlySummaryRequest;
use Modules\MonthlySummary\Http\Resources\MonthlySummaryResource;
use Modules\MonthlySummary\Workflows\CarryForwardMonthlySummaryWorkflow;

class CarryForwardMonthlySummaryController extends Controller
{
    public function __invoke(
        CarryForwardMonthlySummaryRequest $request,
        CarryForwardMonthlySummaryWorkflow $workflow,
        BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ): JsonResponse {
        $carry = $workflow->handle($request->month(), $request->year());

        return $this->successResponse(
            __('messages.monthly_summary_carried_forward_successfully'),
            new MonthlySummaryResource(
                $buildMonthlySummaryAction->execute($carry->to_month, $carry->to_year)
            ),
        );
    }
}
