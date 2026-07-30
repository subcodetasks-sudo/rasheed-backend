<?php

namespace Modules\Settings\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Settings\Actions\UpdateSettingAction;
use Modules\Settings\app\Transformers\SettingResource;
use Modules\Settings\Http\Requests\BulkUpdateSettingsRequest;
use Modules\Settings\Http\Requests\UpdateSettingRequest;
use Modules\Settings\Services\SettingService;

class SettingController extends Controller
{
    public function __construct(
        protected SettingService $service,
        protected UpdateSettingAction $updateSettingAction,
    ) {}

    public function index()
    {
        $settings = $this->service->all();

        return $this->successResponse(
            __('messages.settings_fetched_successfully'),
            SettingResource::collection($settings)
        );
    }

    public function update(UpdateSettingRequest $request, string $key)
    {
        try {
            $updatedValue = $this->updateSettingAction->execute(
                $key,
                $request->validated('value'),
                $request->validated('type') ?? (in_array($key, ['admin_fee_percentage', 'total_operational_deduction'], true) ? 'decimal' : 'string'),
                $request->boolean('is_public', true)
            );

            return $this->successResponse(
                __('messages.setting_updated_successfully'),
                [$key => $updatedValue]
            );
        } catch (\Exception $e) {
            Log::error('Failed to update setting: '.$e->getMessage());

            return $this->errorResponse(
                __('messages.failed_to_update_setting'),
                __('messages.unexpected_error')
            );
        }
    }

    public function bulkUpdate(BulkUpdateSettingsRequest $request)
    {
        try {
            foreach ($request->validated('settings') as $item) {
                $type = $item['type'] ?? (in_array($item['key'], ['admin_fee_percentage', 'total_operational_deduction'], true) ? 'decimal' : 'string');

                $this->updateSettingAction->execute(
                    $item['key'],
                    $item['value'],
                    $type,
                    $item['is_public'] ?? true
                );
            }

            $settings = $this->service->all();

            return $this->successResponse(
                __('messages.setting_updated_successfully'),
                SettingResource::collection($settings)
            );
        } catch (\Exception $e) {
            Log::error('Bulk update failed: '.$e->getMessage());

            return $this->errorResponse(
                __('messages.failed_to_update_setting'),
                __('messages.unexpected_error')
            );
        }
    }
}
