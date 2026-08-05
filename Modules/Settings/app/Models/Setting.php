<?php

namespace Modules\Settings\app\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['key', 'value', 'type', 'is_public'];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    /**
     * Interact with the value attribute.
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => match ($this->type) {
                'integer' => (int) $value,
                'decimal' => $value === null || $value === '' ? null : (float) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($value, true),
                default => $value,
            },
            set: fn ($value) => is_array($value) ? json_encode($value) : $value
        );
    }
}
