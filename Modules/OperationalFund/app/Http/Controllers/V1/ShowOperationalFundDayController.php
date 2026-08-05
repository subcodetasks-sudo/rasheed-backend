<?php

namespace Modules\OperationalFund\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OperationalFund\Http\Requests\ShowOperationalFundDayRequest;
use Modules\OperationalFund\Http\Resources\OperationalFundDayResource;
use Modules\OperationalFund\Workflows\ShowOperationalFundDayWorkflow;

class ShowOperationalFundDayController extends Controller
{
    public function __invoke(
        ShowOperationalFundDayRequest $request,
        ShowOperationalFundDayWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.operational_fund_day_fetched_successfully'),
            new OperationalFundDayResource($workflow->handle($request->fundDate())),
        );
    }
}
