<?php

namespace Modules\Project\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],
            'fund_type' => $this->fund_type?->value,
            'status' => $this->status?->value,
            'operational_deduction_type' => $this->operational_deduction_type?->value,
            'operational_fixed_amount' => $this->when(
                $this->hasFixedOperationalDeduction(),
                $this->operational_fixed_amount
            ),
            'administrative_exempt' => $this->administrative_exempt,
            'administrative_fee_percentage' => $this->administrative_fee_percentage,
            'archived_at' => $this->archived_at?->toDateTimeString(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
