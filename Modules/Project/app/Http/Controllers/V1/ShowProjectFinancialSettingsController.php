<?php

namespace Modules\Project\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Project\Actions\Project\GetProjectFinancialSettingsAction;
use Modules\Project\Http\Resources\ProjectFinancialSettingsResource;

class ShowProjectFinancialSettingsController extends Controller
{
    public function __invoke(GetProjectFinancialSettingsAction $action): JsonResponse
    {
        return $this->successResponse(
            __('messages.settings_fetched_successfully'),
            new ProjectFinancialSettingsResource($action->execute())
        );
    }
}
