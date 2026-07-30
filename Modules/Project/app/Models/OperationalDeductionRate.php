<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalDeductionRate extends Model
{
    protected $fillable = [
        'amount',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }
}
