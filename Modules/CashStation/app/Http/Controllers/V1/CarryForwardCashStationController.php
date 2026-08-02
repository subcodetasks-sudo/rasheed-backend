<?php

namespace Modules\CashStation\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Http\Requests\CarryForwardCashStationRequest;
use Modules\CashStation\Http\Resources\CashStationResource;
use Modules\CashStation\Workflows\CarryForwardCashStationWorkflow;

class CarryForwardCashStationController extends Controller
{
    public function __invoke(
        CarryForwardCashStationRequest $request,
        CarryForwardCashStationWorkflow $workflow,
        BuildCashStationAction $buildCashStationAction,
    ): JsonResponse {
        $carry = $workflow->handle($request->month(), $request->year());

        return $this->successResponse(
            __('messages.cash_station_carried_forward_successfully'),
            new CashStationResource(
                $buildCashStationAction->execute($carry->to_month, $carry->to_year)
            ),
        );
    }
}
