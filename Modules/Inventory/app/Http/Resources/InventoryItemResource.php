<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'unit' => $this->unit,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'project_id' => $this->project_id,
            'latest_incoming_price' => $this->latest_incoming_price,
            'opening_quantity' => $this->opening_quantity,
            'total_incoming_quantity' => $this->total_incoming_quantity,
            'total_outgoing_quantity' => $this->total_outgoing_quantity,
            'current_balance' => $this->current_balance,
            'minimum_stock_level' => $this->minimum_stock_level,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
