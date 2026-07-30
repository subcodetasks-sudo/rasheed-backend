<?php

namespace Modules\Dashboard\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Dashboard\Actions\BuildDashboardSummaryAction;
use Modules\Dashboard\Http\Requests\ShowDashboardRequest;
use Modules\Dashboard\Http\Resources\DashboardResource;

class ShowDashboardController extends Controller
{
    public function __invoke(
        ShowDashboardRequest $request,
        BuildDashboardSummaryAction $action
    ): JsonResponse {
        return $this->successResponse(
            __('messages.dashboard_fetched_successfully'),
            new DashboardResource($action->execute($request->journalDate()))
        );
    }
}
