<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class SystemGeneralSetting extends Model
{
    protected $table = 'system_general_settings';

    protected $fillable = [
        'organization_name',
        'currency',
        'admin_fee_percentage',
    ];

    protected function casts(): array
    {
        return [
            'admin_fee_percentage' => 'decimal:2',
        ];
    }

    public static function singleton(): self
    {
        $row = static::query()->first();

        if ($row !== null) {
            return $row;
        }

        return static::query()->create([
            'organization_name' => 'Rashid Financial',
            'currency' => 'Shekel',
            'admin_fee_percentage' => 12,
        ]);
    }
}
