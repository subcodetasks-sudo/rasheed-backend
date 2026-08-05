<?php

namespace Modules\OperationalFund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalFundMonthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'year' => $this->resource['year'],
            'summary' => $this->resource['summary'],
            'days' => $this->resource['days'],
        ];
    }
}
