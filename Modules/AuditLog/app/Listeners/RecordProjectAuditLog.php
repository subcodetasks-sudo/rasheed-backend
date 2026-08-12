<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\Project\Events\ProjectArchived;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Events\ProjectDeleted;
use Modules\Project\Events\ProjectRestored;
use Modules\Project\Events\ProjectUpdated;

class RecordProjectAuditLog
{
    use RecordsAuditSafely;

    public function handle(ProjectCreated|ProjectUpdated|ProjectArchived|ProjectDeleted|ProjectRestored $event): void
    {
        if ($event instanceof ProjectDeleted) {
            $this->record(
                AuditAction::Deleted,
                ArabicLocale::trans('messages.audit_project_deleted', ['name' => $event->projectName]),
                properties: ['project_id' => $event->projectId],
            );

            return;
        }

        [$action, $key] = match (true) {
            $event instanceof ProjectCreated => [AuditAction::Created, 'audit_project_created'],
            $event instanceof ProjectUpdated => [AuditAction::Updated, 'audit_project_updated'],
            $event instanceof ProjectArchived => [AuditAction::Archived, 'audit_project_archived'],
            $event instanceof ProjectRestored => [AuditAction::Restored, 'audit_project_restored'],
        };

        $this->record(
            $action,
            ArabicLocale::trans("messages.{$key}", ['name' => $event->project->name]),
            subject: $event->project,
            properties: ['project_id' => $event->project->id],
        );
    }
}
