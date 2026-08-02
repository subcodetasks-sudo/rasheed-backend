<?php

namespace Modules\CashStation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\app\Models\User;

class CashStationMonthCarry extends Model
{
    protected $table = 'cash_station_month_carries';

    protected $fillable = [
        'from_year',
        'from_month',
        'to_year',
        'to_month',
        'carried_by',
    ];

    protected function casts(): array
    {
        return [
            'from_year' => 'integer',
            'from_month' => 'integer',
            'to_year' => 'integer',
            'to_month' => 'integer',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'carried_by', 'uuid');
    }
}
