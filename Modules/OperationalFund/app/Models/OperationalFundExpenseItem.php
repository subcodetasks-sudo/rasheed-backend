<?php

namespace Modules\OperationalFund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\app\Models\User;

class OperationalFundExpenseItem extends Model
{
    protected $table = 'operational_fund_expense_items';

    protected $fillable = [
        'operational_fund_day_id',
        'amount',
        'description',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(OperationalFundDay::class, 'operational_fund_day_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }
}
