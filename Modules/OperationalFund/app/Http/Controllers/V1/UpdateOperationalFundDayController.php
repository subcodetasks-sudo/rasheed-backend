<?php

namespace Modules\OperationalFund\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OperationalFund\Http\Requests\UpdateOperationalFundDayRequest;
use Modules\OperationalFund\Http\Resources\OperationalFundMonthResource;
use Modules\OperationalFund\Workflows\UpdateOperationalFundDayWorkflow;

class UpdateOperationalFundDayController extends Controller
{
    public function __invoke(
        string $date,
        UpdateOperationalFundDayRequest $request,
        UpdateOperationalFundDayWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.operational_fund_updated_successfully'),
            new OperationalFundMonthResource(
                $workflow->handle($date, $request->operationalExpense()),
            ),
        );
    }
}
