<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AdministrativeFund\Events\AdministrativeFundUpdated;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;

class RecordAdministrativeFundAuditLog
{
    use RecordsAuditSafely;

    public function handle(AdministrativeFundUpdated $event): void
    {
        if (! $this->isMutatingPath('api/v1/administrative-fund', ['PUT'])) {
            return;
        }

        $this->record(
            AuditAction::Saved,
            ArabicLocale::trans('messages.audit_administrative_fund_saved', [
                'month' => $event->month,
                'year' => $event->year,
            ]),
            properties: [
                'year' => $event->year,
                'month' => $event->month,
            ],
        );
    }
}
