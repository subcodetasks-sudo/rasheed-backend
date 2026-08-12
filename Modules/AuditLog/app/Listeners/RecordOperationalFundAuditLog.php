<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\OperationalFund\Events\OperationalFundUpdated;

class RecordOperationalFundAuditLog
{
    use RecordsAuditSafely;

    public function handle(OperationalFundUpdated $event): void
    {
        if (! $this->isMutatingPath('api/v1/operational-fund', ['PUT'])) {
            return;
        }

        $this->record(
            AuditAction::Saved,
            ArabicLocale::trans('messages.audit_operational_fund_saved', [
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
