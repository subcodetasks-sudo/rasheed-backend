<?php

namespace Modules\MonthlySummary\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MonthlySummary\Http\Requests\ListBeneficiaryOptionsRequest;
use Modules\MonthlySummary\Workflows\ListBeneficiaryOptionsWorkflow;

class ListBeneficiaryOptionsController extends Controller
{
    public function __invoke(
        ListBeneficiaryOptionsRequest $request,
        ListBeneficiaryOptionsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.monthly_summary_beneficiary_options_fetched_successfully'),
            $workflow->handle(
                $request->month(),
                $request->year(),
                $request->fromProjectId(),
                $request->contributionType(),
            ),
        );
    }
}
