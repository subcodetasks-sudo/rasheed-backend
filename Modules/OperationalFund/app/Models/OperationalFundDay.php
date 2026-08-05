<?php

namespace Modules\OperationalFund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\app\Models\User;

class OperationalFundDay extends Model
{
    protected $table = 'operational_fund_days';

    protected $fillable = [
        'date',
        'operational_expense',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'operational_expense' => 'decimal:2',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }
}
