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

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
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
