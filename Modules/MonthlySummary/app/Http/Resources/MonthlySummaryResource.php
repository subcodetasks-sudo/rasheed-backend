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
            'locked' => (bool) ($this->resource['locked'] ?? false),
            'frozen_at' => $this->resource['frozen_at'] ?? null,
            'calculation_date' => $this->resource['calculation_date'],
            'carried_forward_from_previous' => $this->resource['carried_forward_from_previous'],
            'projects' => $this->resource['projects'],
            'contributions' => $this->resource['contributions'],
        ];
    }
}
