<?php

namespace Modules\AuditLog\Policies;

use Modules\User\app\Models\User;
use Spatie\Activitylog\Models\Activity;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super-admin') || $user->can('view-audit-logs');
    }

    public function viewOwn(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'finance', 'inventory']);
    }

    public function view(User $user, Activity $activity): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        return $this->viewOwn($user)
            && (string) $activity->causer_id === (string) $user->uuid;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
