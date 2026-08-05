<?php

namespace Modules\OperationalFund\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalFundDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->resource['date'],
            'expected_operational_income' => $this->resource['expected_operational_income'],
            'operational_expense' => $this->resource['operational_expense'],
            'daily_operational_net' => $this->resource['daily_operational_net'],
            'day_status' => $this->resource['day_status'],
        ];
    }
}
