<?php

namespace Modules\AdministrativeFund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdministrativeFundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'year' => $this->resource['year'],
            'summary' => $this->resource['summary'],
            'days' => $this->resource['days'],
            'totals' => $this->resource['totals'],
        ];
    }
}
