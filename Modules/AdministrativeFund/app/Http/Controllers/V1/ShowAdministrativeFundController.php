<?php

namespace Modules\AdministrativeFund\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AdministrativeFund\Http\Requests\ShowAdministrativeFundRequest;
use Modules\AdministrativeFund\Http\Resources\AdministrativeFundResource;
use Modules\AdministrativeFund\Workflows\ShowAdministrativeFundWorkflow;

class ShowAdministrativeFundController extends Controller
{
    public function __invoke(
        ShowAdministrativeFundRequest $request,
        ShowAdministrativeFundWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.administrative_fund_fetched_successfully'),
            new AdministrativeFundResource($workflow->handle($request->month(), $request->year())),
        );
    }
}
