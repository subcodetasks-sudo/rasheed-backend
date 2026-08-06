<?php

namespace Modules\Settings\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Settings\Http\Resources\SystemGeneralSettingsResource;
use Modules\Settings\Workflows\ShowSystemGeneralSettingsWorkflow;

class ShowSystemGeneralSettingsController extends Controller
{
    public function __invoke(ShowSystemGeneralSettingsWorkflow $workflow): JsonResponse
    {
        return $this->successResponse(
            __('messages.general_settings_fetched_successfully'),
            new SystemGeneralSettingsResource($workflow->handle()),
        );
    }
}
