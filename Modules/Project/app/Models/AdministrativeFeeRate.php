<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrativeFeeRate extends Model
{
    protected $fillable = [
        'percentage',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }
}
