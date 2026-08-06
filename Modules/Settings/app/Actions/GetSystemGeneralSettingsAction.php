<?php

namespace Modules\Settings\Actions;

use Modules\Settings\Models\SystemGeneralSetting;

class GetSystemGeneralSettingsAction
{
    /**
     * @return array{organization_name: string, currency: string, admin_fee_percentage: float}
     */
    public function execute(): array
    {
        $row = SystemGeneralSetting::singleton();

        return [
            'organization_name' => $row->organization_name,
            'currency' => $row->currency,
            'admin_fee_percentage' => round((float) $row->admin_fee_percentage, 2),
        ];
    }
}
