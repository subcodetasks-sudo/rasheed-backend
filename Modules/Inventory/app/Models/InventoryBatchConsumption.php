<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatchConsumption extends Model
{
    protected $fillable = [
        'outgoing_movement_id',
        'batch_id',
        'quantity',
        'unit_cost',
        'line_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_cost' => 'decimal:2',
        ];
    }

    public function outgoingMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'outgoing_movement_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }
}
