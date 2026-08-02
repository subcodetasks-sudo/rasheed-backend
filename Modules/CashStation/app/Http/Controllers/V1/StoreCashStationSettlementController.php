<?php

namespace Modules\CashStation\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Http\Requests\StoreCashStationSettlementRequest;
use Modules\CashStation\Http\Resources\CashStationResource;
use Modules\CashStation\Workflows\StoreCashStationSettlementWorkflow;

class StoreCashStationSettlementController extends Controller
{
    public function __invoke(
        StoreCashStationSettlementRequest $request,
        StoreCashStationSettlementWorkflow $workflow,
        BuildCashStationAction $buildCashStationAction,
    ): JsonResponse {
        $settlement = $workflow->handle(
            $request->year(),
            $request->month(),
            $request->fromProjectId(),
            $request->toProjectId(),
            $request->amount(),
        );

        return $this->successResponse(
            __('messages.cash_station_settlement_created_successfully'),
            new CashStationResource(
                $buildCashStationAction->execute($settlement->month, $settlement->year)
            ),
            201,
        );
    }
}
