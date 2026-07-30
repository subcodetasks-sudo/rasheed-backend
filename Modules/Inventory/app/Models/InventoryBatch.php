<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\InventoryBatchSourceType;

class InventoryBatch extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'source_type',
        'source_movement_id',
        'unit_cost',
        'original_quantity',
        'remaining_quantity',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => InventoryBatchSourceType::class,
            'unit_cost' => 'decimal:2',
            'original_quantity' => 'decimal:2',
            'remaining_quantity' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'source_movement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryBatchConsumption::class, 'batch_id');
    }
}
