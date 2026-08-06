<?php

namespace Modules\Settings\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemGeneralSettingsUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{organization_name: string, currency: string, admin_fee_percentage: float}  $settings
     */
    public function __construct(
        public readonly array $settings,
    ) {}
}
