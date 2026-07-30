<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'type' => $this->type?->value,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_cost' => $this->total_cost,
            'beneficiary_project_id' => $this->beneficiary_project_id,
            'expense_type' => $this->expense_type?->value,
            'notes' => $this->notes,
            'movement_date' => $this->movement_date?->toDateString(),
            'item' => $this->whenLoaded('item', fn () => new InventoryItemResource($this->item)),
            'beneficiary_project' => $this->whenLoaded('beneficiaryProject', fn () => [
                'id' => $this->beneficiaryProject->id,
                'name' => $this->beneficiaryProject->name,
            ]),
            'consumptions' => $this->whenLoaded('consumptions', fn () => $this->consumptions->map(fn ($c) => [
                'batch_id' => $c->batch_id,
                'quantity' => $c->quantity,
                'unit_cost' => $c->unit_cost,
                'line_cost' => $c->line_cost,
            ])),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
