<?php

namespace Modules\AuditLog\Listeners;

use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\Settings\Events\MonthlyEmployeeSettingsUpdated;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;

class RecordSettingsAuditLog
{
    use RecordsAuditSafely;

    public function handle(SystemGeneralSettingsUpdated|MonthlyEmployeeSettingsUpdated $event): void
    {
        if ($event instanceof SystemGeneralSettingsUpdated) {
            $this->record(
                AuditAction::Updated,
                __('messages.audit_general_settings_updated'),
            );

            return;
        }

        $this->record(
            AuditAction::Updated,
            __('messages.audit_monthly_employee_settings_updated', [
                'month' => $event->month,
                'year' => $event->year,
            ]),
            properties: [
                'month' => $event->month,
                'year' => $event->year,
            ],
        );
    }
}
