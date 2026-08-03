<?php

namespace Modules\AdministrativeDebtSettlement\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AdministrativeDebtSettlement\Http\Requests\ListAdministrativeDebtSettlementsRequest;
use Modules\AdministrativeDebtSettlement\Http\Resources\AdministrativeDebtSettlementListResource;
use Modules\AdministrativeDebtSettlement\Workflows\ListAdministrativeDebtSettlementsWorkflow;

class ListAdministrativeDebtSettlementsController extends Controller
{
    public function __invoke(
        ListAdministrativeDebtSettlementsRequest $request,
        ListAdministrativeDebtSettlementsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.administrative_debt_settlements_fetched_successfully'),
            new AdministrativeDebtSettlementListResource(
                $workflow->handle($request->month(), $request->year())
            ),
        );
    }
}
