<?php

namespace Modules\AdministrationRates\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AdministrationRates\Actions\BuildAdministrationRatesAction;
use Modules\AdministrationRates\Http\Requests\ShowAdministrationRatesRequest;
use Modules\AdministrationRates\Http\Resources\AdministrationRatesResource;

class ShowAdministrationRatesController extends Controller
{
    public function __invoke(
        ShowAdministrationRatesRequest $request,
        BuildAdministrationRatesAction $action
    ): JsonResponse {
        return $this->successResponse(
            __('messages.administration_rates_fetched_successfully'),
            new AdministrationRatesResource($action->execute($request->month(), $request->year()))
        );
    }
}
