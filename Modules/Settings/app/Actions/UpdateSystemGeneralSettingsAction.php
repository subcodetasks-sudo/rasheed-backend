<?php

namespace Modules\Settings\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;
use Modules\Settings\Models\SystemGeneralSetting;

class UpdateSystemGeneralSettingsAction
{
    public function __construct(
        private readonly GetSystemGeneralSettingsAction $getSystemGeneralSettingsAction,
    ) {}

    /**
     * @param  array{organization_name?: string, admin_fee_percentage?: float|int|string}  $data
     * @return array{organization_name: string, currency: string, admin_fee_percentage: float}
     */
    public function execute(array $data): array
    {
        $settings = DB::transaction(function () use ($data) {
            $row = SystemGeneralSetting::singleton();

            $updates = [];

            if (array_key_exists('organization_name', $data)) {
                $updates['organization_name'] = (string) $data['organization_name'];
            }

            if (array_key_exists('admin_fee_percentage', $data)) {
                $newAdminFee = round((float) $data['admin_fee_percentage'], 2);
                $currentAdminFee = round((float) $row->admin_fee_percentage, 2);

                if ($newAdminFee !== $currentAdminFee) {
                    $updates['admin_fee_percentage'] = $newAdminFee;
                }
            }

            if ($updates !== []) {
                $row->fill($updates);
                $row->save();
            }

            return $this->getSystemGeneralSettingsAction->execute();
        });

        SystemGeneralSettingsUpdated::dispatch($settings);

        return $settings;
    }
}
