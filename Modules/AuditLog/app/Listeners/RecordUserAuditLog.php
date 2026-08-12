<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\User\app\Events\UserCreated;
use Modules\User\app\Events\UserDeleted;
use Modules\User\app\Events\UserStatusUpdated;
use Modules\User\app\Events\UserUpdated;

class RecordUserAuditLog
{
    use RecordsAuditSafely;

    public function handle(UserCreated|UserUpdated|UserDeleted|UserStatusUpdated $event): void
    {
        if ($event instanceof UserDeleted) {
            $this->record(
                AuditAction::Deleted,
                ArabicLocale::trans('messages.audit_user_deleted', ['name' => $event->userFullName]),
                properties: ['user_uuid' => $event->userUuid],
            );

            return;
        }

        if ($event instanceof UserStatusUpdated) {
            $this->record(
                AuditAction::StatusUpdated,
                ArabicLocale::trans('messages.audit_user_status_updated', [
                    'name' => $event->user->full_name,
                    'status' => ArabicLocale::label((string) $event->user->status),
                ]),
                subject: $event->user,
                properties: [
                    'user_uuid' => $event->user->uuid,
                    'status' => $event->user->status,
                ],
            );

            return;
        }

        $action = $event instanceof UserCreated ? AuditAction::Created : AuditAction::Updated;
        $key = $event instanceof UserCreated ? 'audit_user_created' : 'audit_user_updated';

        $this->record(
            $action,
            ArabicLocale::trans("messages.{$key}", ['name' => $event->user->full_name]),
            subject: $event->user,
            properties: ['user_uuid' => $event->user->uuid],
        );
    }
}
