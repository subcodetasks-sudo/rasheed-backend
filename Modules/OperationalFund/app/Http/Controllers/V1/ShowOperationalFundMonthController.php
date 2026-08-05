<?php

namespace Modules\OperationalFund\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OperationalFund\Http\Requests\ShowOperationalFundMonthRequest;
use Modules\OperationalFund\Http\Resources\OperationalFundMonthResource;
use Modules\OperationalFund\Workflows\ShowOperationalFundMonthWorkflow;

class ShowOperationalFundMonthController extends Controller
{
    public function __invoke(
        ShowOperationalFundMonthRequest $request,
        ShowOperationalFundMonthWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.operational_fund_fetched_successfully'),
            new OperationalFundMonthResource($workflow->handle($request->month(), $request->year())),
        );
    }
}
