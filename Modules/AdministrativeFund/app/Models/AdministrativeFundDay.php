<?php

namespace Modules\AdministrativeFund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\app\Models\User;

class AdministrativeFundDay extends Model
{
    protected $table = 'administrative_fund_days';

    protected $fillable = [
        'date',
        'individual_contributions',
        'asset_administration',
        'operational_administration',
        'notes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'individual_contributions' => 'decimal:2',
            'asset_administration' => 'decimal:2',
            'operational_administration' => 'decimal:2',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }
}
