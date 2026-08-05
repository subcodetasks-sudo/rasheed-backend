<?php

namespace Modules\ReportsCenter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportsCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
