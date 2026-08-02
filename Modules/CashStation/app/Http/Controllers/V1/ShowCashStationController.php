<?php

namespace Modules\CashStation\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Http\Requests\ShowCashStationRequest;
use Modules\CashStation\Http\Resources\CashStationResource;

class ShowCashStationController extends Controller
{
    public function __invoke(
        ShowCashStationRequest $request,
        BuildCashStationAction $action
    ): JsonResponse {
        return $this->successResponse(
            __('messages.cash_station_fetched_successfully'),
            new CashStationResource($action->execute($request->month(), $request->year()))
        );
    }
}
