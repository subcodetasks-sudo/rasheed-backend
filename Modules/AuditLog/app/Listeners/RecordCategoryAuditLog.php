<?php

namespace Modules\AuditLog\Listeners;

use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\Project\Events\CategoryCreated;
use Modules\Project\Events\CategoryDeleted;
use Modules\Project\Events\CategoryUpdated;

class RecordCategoryAuditLog
{
    use RecordsAuditSafely;

    public function handle(CategoryCreated|CategoryUpdated|CategoryDeleted $event): void
    {
        if ($event instanceof CategoryDeleted) {
            $this->record(
                AuditAction::Deleted,
                __('messages.audit_category_deleted', ['name' => $event->categoryName]),
                properties: ['category_id' => $event->categoryId],
            );

            return;
        }

        $action = $event instanceof CategoryCreated ? AuditAction::Created : AuditAction::Updated;
        $key = $event instanceof CategoryCreated ? 'audit_category_created' : 'audit_category_updated';

        $this->record(
            $action,
            __("messages.{$key}", ['name' => $event->category->name]),
            subject: $event->category,
            properties: ['category_id' => $event->category->id],
        );
    }
}
