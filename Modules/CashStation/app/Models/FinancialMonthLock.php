<?php

namespace Modules\CashStation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\app\Models\User;

class FinancialMonthLock extends Model
{
    protected $table = 'financial_month_locks';

    protected $fillable = [
        'year',
        'month',
        'frozen_at',
        'frozen_by',
        'carried_forward_from_previous',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'frozen_at' => 'datetime',
            'carried_forward_from_previous' => 'boolean',
            'calculation_date' => 'date',
        ];
    }

    public function freezer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by', 'uuid');
    }
}
