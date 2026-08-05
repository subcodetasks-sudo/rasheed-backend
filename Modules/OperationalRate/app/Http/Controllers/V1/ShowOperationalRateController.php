<?php

namespace Modules\OperationalRate\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OperationalRate\Http\Requests\ShowOperationalRateRequest;
use Modules\OperationalRate\Http\Resources\OperationalRateResource;
use Modules\OperationalRate\Workflows\ShowOperationalRateWorkflow;

class ShowOperationalRateController extends Controller
{
    public function __invoke(
        ShowOperationalRateRequest $request,
        ShowOperationalRateWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.operational_rate_fetched_successfully'),
            new OperationalRateResource($workflow->handle($request->month(), $request->year())),
        );
    }
}
