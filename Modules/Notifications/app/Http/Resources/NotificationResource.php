<?php

namespace Modules\Notifications\Http\Resources;

use App\Support\ArabicLocale;
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
        $meta = is_array($this->meta) ? $this->meta : [];

        return [
            'id' => $this->id,
            'type' => NotificationPageTypeMapper::toPageType($this->type),
            'title' => $this->title,
            'details' => $this->resolvedDetails($meta),
            'actor' => isset($meta['actor_name']) && $meta['actor_name'] !== ''
                ? [
                    'uuid' => $meta['actor_id'] ?? null,
                    'full_name' => $meta['actor_name'],
                ]
                : null,
            'project' => $this->project
                ? [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                ]
                : null,
            'created_at' => $this->created_at?->toISOString(),
            'read_at' => $this->resolveReadAt(),
        ];
    }

    protected function resolveReadAt(): ?string
    {
        if ($this->relationLoaded('reads')) {
            return $this->reads->first()?->read_at?->toISOString();
        }

        $user = request()->user();
        if ($user === null) {
            return null;
        }

        $read = $this->reads()
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        return $read?->read_at?->toISOString();
    }

    /**
     * Prefer the live project name so renames are reflected (stored message can be stale).
     *
     * @param  array<string, mixed>  $meta
     */
    protected function resolvedDetails(array $meta): string
    {
        $action = $meta['action'] ?? null;
        $projectActions = ['created', 'updated', 'archived', 'deleted', 'restored'];

        if (
            $this->project
            && is_string($action)
            && in_array($action, $projectActions, true)
        ) {
            $message = ArabicLocale::trans('messages.notification_project_'.$action.'_message', [
                'name' => $this->project->name,
            ]);

            $actorName = trim((string) ($meta['actor_name'] ?? ''));
            if ($actorName !== '') {
                $suffix = ArabicLocale::trans('messages.notification_by_actor', ['name' => $actorName]);
                $trimmed = rtrim($message);
                $message = str_ends_with($trimmed, '.')
                    ? substr($trimmed, 0, -1).' '.$suffix.'.'
                    : $trimmed.' '.$suffix;
            }

            return str_replace('"', '', $message);
        }

        return str_replace('"', '', (string) $this->message);
    }
}
