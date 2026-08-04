<?php

namespace Modules\MonthlySummary\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'calculation_date' => $this->resource['calculation_date'],
            'projects' => $this->resource['projects'],
            'contributions' => $this->resource['contributions'],
        ];
    }
}
