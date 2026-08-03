<?php

namespace Modules\AdministrativeDebtSettlement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AdministrativeDebtSettlement\Enums\AdministrativeDebtSettlementStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;

class AdministrativeDebtSettlement extends Model
{
    protected $table = 'administrative_debt_settlements';

    protected $fillable = [
        'year',
        'month',
        'project_id',
        'surplus_at_settlement',
        'recoverable_amount',
        'allocated_cash_box',
        'allocated_current_debt',
        'allocated_carried_debt',
        'status',
        'settled_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'surplus_at_settlement' => 'decimal:2',
            'recoverable_amount' => 'decimal:2',
            'allocated_cash_box' => 'decimal:2',
            'allocated_current_debt' => 'decimal:2',
            'allocated_carried_debt' => 'decimal:2',
            'status' => AdministrativeDebtSettlementStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by', 'uuid');
    }
}
