<?php

namespace Modules\DailyJournal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;

class AdminPercentageBalanceCredit extends Model
{
    protected $fillable = [
        'administrative_debt_settlement_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AdministrativeDebtSettlement::class, 'administrative_debt_settlement_id');
    }
}
