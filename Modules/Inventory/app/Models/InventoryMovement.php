<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;

class InventoryMovement extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'unit_price',
        'total_cost',
        'beneficiary_project_id',
        'expense_type',
        'notes',
        'movement_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'expense_type' => InventoryExpenseType::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function beneficiaryProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'beneficiary_project_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryBatchConsumption::class, 'outgoing_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }
}
