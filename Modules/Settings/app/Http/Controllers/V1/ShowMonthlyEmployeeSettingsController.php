<?php

namespace Modules\Settings\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Settings\Http\Requests\ShowMonthlyEmployeeSettingsRequest;
use Modules\Settings\Http\Resources\MonthlyEmployeeSettingsResource;
use Modules\Settings\Workflows\ShowMonthlyEmployeeSettingsWorkflow;

class ShowMonthlyEmployeeSettingsController extends Controller
{
    public function __invoke(
        ShowMonthlyEmployeeSettingsRequest $request,
        ShowMonthlyEmployeeSettingsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.monthly_employee_settings_fetched_successfully'),
            new MonthlyEmployeeSettingsResource(
                $workflow->handle($request->month(), $request->year()),
            ),
        );
    }
}
