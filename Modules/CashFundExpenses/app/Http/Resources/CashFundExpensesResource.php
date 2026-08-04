<?php

namespace Modules\CashFundExpenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashFundExpensesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'year' => $this->resource['year'],
            'days' => $this->resource['days'],
            'projects' => $this->resource['projects'],
        ];
    }
}
