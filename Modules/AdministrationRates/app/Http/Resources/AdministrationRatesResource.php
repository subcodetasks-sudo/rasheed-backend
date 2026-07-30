<?php

namespace Modules\AdministrationRates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdministrationRatesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
