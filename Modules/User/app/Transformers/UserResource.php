<?php

namespace Modules\User\app\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid ?? $this->id,
            'name' => $this->full_name,
            'user_name' => $this->user_name,
            'email' => $this->email,
            'status' => $this->status,
            'lastLoginAt' => $this->last_login_at?->toDateTimeString(),
            'role' => $this->roles->first()?->name,
        ];
    }
}