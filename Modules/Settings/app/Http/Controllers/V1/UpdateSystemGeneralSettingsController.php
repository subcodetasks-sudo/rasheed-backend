<?php

namespace Modules\Settings\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Settings\Http\Requests\UpdateSystemGeneralSettingsRequest;
use Modules\Settings\Http\Resources\SystemGeneralSettingsResource;
use Modules\Settings\Workflows\UpdateSystemGeneralSettingsWorkflow;

class UpdateSystemGeneralSettingsController extends Controller
{
    public function __invoke(
        UpdateSystemGeneralSettingsRequest $request,
        UpdateSystemGeneralSettingsWorkflow $workflow,
    ): JsonResponse {
        return $this->successResponse(
            __('messages.general_settings_updated_successfully'),
            new SystemGeneralSettingsResource($workflow->handle($request->settings())),
        );
    }
}
