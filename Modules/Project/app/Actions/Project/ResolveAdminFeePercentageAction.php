<?php

namespace Modules\Project\Actions\Project;

use Modules\Settings\Models\SystemGeneralSetting;

class ResolveAdminFeePercentageAction
{
    public const DEFAULT_PERCENTAGE = 12.0;

    public function execute(): float
    {
        $row = SystemGeneralSetting::query()->first();

        if ($row === null) {
            return self::DEFAULT_PERCENTAGE;
        }

        return round((float) $row->admin_fee_percentage, 2);
    }
}
