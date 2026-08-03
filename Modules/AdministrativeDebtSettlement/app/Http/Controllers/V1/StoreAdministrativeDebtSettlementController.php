<?php

namespace Modules\AdministrativeDebtSettlement\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Http\Requests\StoreAdministrativeDebtSettlementRequest;
use Modules\AdministrativeDebtSettlement\Http\Resources\AdministrativeDebtSettlementListResource;
use Modules\AdministrativeDebtSettlement\Workflows\StoreAdministrativeDebtSettlementWorkflow;

class StoreAdministrativeDebtSettlementController extends Controller
{
    public function __invoke(
        StoreAdministrativeDebtSettlementRequest $request,
        StoreAdministrativeDebtSettlementWorkflow $workflow,
        BuildAdministrativeDebtSettlementAction $buildAction,
    ): JsonResponse {
        $settlement = $workflow->handle(
            $request->year(),
            $request->month(),
            $request->projectId(),
            $request->amount(),
        );

        return $this->successResponse(
            __('messages.administrative_debt_settlement_created_successfully'),
            new AdministrativeDebtSettlementListResource(
                $buildAction->execute($settlement->month, $settlement->year)
            ),
            201,
        );
    }
}
