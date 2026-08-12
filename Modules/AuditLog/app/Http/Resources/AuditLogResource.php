<?php

namespace Modules\AuditLog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $properties = $this->properties instanceof Collection
            ? $this->properties->toArray()
            : (array) $this->properties;

        $causer = $this->causer;

        return [
            'id' => $this->id,
            'occurred_at' => $this->created_at?->toISOString(),
            'user' => $this->causer_id || $causer
                ? [
                    'uuid' => $causer->uuid ?? $this->causer_id,
                    'full_name' => $causer->full_name ?? $properties['actor_name'] ?? null,
                ]
                : null,
            'action' => $properties['action'] ?? $this->event,
            'source' => $properties['source'] ?? 'api',
            'description' => $this->description,
            'ip_address' => $properties['ip'] ?? null,
        ];
    }
}
