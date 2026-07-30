<?php

namespace Modules\Project\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFinancialSettingsResource extends JsonResource
{
    /**
     * @param  array{admin_fee_percentage: float, total_operational_deduction: float}  $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'admin_fee_percentage' => (float) $this->resource['admin_fee_percentage'],
            'total_operational_deduction' => (float) $this->resource['total_operational_deduction'],
        ];
    }
}
