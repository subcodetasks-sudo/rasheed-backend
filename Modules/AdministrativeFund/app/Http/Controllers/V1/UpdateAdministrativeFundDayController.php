<?php

namespace Modules\AdministrativeFund\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AdministrativeFund\Http\Requests\UpdateAdministrativeFundDayRequest;
use Modules\AdministrativeFund\Http\Resources\AdministrativeFundResource;
use Modules\AdministrativeFund\Workflows\UpdateAdministrativeFundDayWorkflow;

class UpdateAdministrativeFundDayController extends Controller
{
    public function __invoke(
        string $date,
        UpdateAdministrativeFundDayRequest $request,
        UpdateAdministrativeFundDayWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.administrative_fund_updated_successfully'),
            new AdministrativeFundResource($workflow->handle($date, $request->editablePayload())),
        );
    }
}
