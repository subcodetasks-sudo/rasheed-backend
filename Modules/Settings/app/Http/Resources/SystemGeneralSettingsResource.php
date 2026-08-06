<?php

namespace Modules\Settings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemGeneralSettingsResource extends JsonResource
{
    /**
     * @param  array{organization_name: string, currency: string, admin_fee_percentage: float}  $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'organization_name' => $this->resource['organization_name'],
            'currency' => $this->resource['currency'],
            'admin_fee_percentage' => (float) $this->resource['admin_fee_percentage'],
        ];
    }
}
