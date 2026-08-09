<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Notifications\Enums\NotificationType;
use Modules\Project\Models\Project;

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
        'project_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
