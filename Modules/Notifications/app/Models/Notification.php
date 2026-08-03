<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Notifications\Enums\NotificationType;

class Notification extends Model
{
    protected $table = 'activity_notifications';

    protected $fillable = [
        'type',
        'title',
        'message',
        'meta',
        'subject_type',
        'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'meta' => 'array',
        ];
    }
}
