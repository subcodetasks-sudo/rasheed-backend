<?php

namespace Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Notifications\Support\NotificationPageTypeMapper;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => NotificationPageTypeMapper::toPageType($this->type),
            'title' => $this->title,
            'details' => $this->message,
            'project' => $this->project
                ? [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                ]
                : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
