<?php

namespace Modules\Settings\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Settings\Http\Requests\UpdateMonthlyEmployeeSettingsRequest;
use Modules\Settings\Http\Resources\MonthlyEmployeeSettingsResource;
use Modules\Settings\Workflows\UpdateMonthlyEmployeeSettingsWorkflow;

class UpdateMonthlyEmployeeSettingsController extends Controller
{
    public function __invoke(
        UpdateMonthlyEmployeeSettingsRequest $request,
        UpdateMonthlyEmployeeSettingsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.monthly_employee_settings_updated_successfully'),
            new MonthlyEmployeeSettingsResource(
                $workflow->handle(
                    $request->month(),
                    $request->year(),
                    $request->categories(),
                ),
            ),
        );
    }
}
