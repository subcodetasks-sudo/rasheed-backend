<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Database\Factories\InventoryItemFactory;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'project_id',
        'name',
        'category',
        'unit',
        'latest_incoming_price',
        'opening_quantity',
        'total_incoming_quantity',
        'total_outgoing_quantity',
        'current_balance',
        'minimum_stock_level',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'latest_incoming_price' => 'decimal:2',
            'opening_quantity' => 'decimal:2',
            'total_incoming_quantity' => 'decimal:2',
            'total_outgoing_quantity' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'minimum_stock_level' => 'decimal:2',
        ];
    }

    protected static function newFactory(): InventoryItemFactory
    {
        return InventoryItemFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }
}
