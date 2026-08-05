<?php

namespace Modules\AdvancedReports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvancedReportsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
