<?php

namespace Modules\CashStation\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Actions\DeleteCashStationSettlementAction;
use Modules\CashStation\Http\Resources\CashStationResource;

class DeleteCashStationSettlementController extends Controller
{
    public function __invoke(
        int $settlement,
        DeleteCashStationSettlementAction $deleteCashStationSettlementAction,
        BuildCashStationAction $buildCashStationAction,
    ): JsonResponse {
        $period = $deleteCashStationSettlementAction->execute($settlement);

        return $this->successResponse(
            __('messages.cash_station_settlement_deleted_successfully'),
            new CashStationResource(
                $buildCashStationAction->execute($period['month'], $period['year'])
            ),
        );
    }
}
