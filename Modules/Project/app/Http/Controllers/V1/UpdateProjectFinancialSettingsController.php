<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Actions\Project\UpdateProjectFinancialSettingsAction;
use Modules\Project\Http\Requests\UpdateProjectFinancialSettingsRequest;
use Modules\Project\Http\Resources\ProjectFinancialSettingsResource;

class UpdateProjectFinancialSettingsController extends Controller
{
    public function __invoke(
        UpdateProjectFinancialSettingsRequest $request,
        UpdateProjectFinancialSettingsAction $action
    ): JsonResponse {
        $settings = $action->execute($request->validated());

        return $this->successResponse(
            __('messages.setting_updated_successfully'),
            new ProjectFinancialSettingsResource($settings)
        );
    }
}
