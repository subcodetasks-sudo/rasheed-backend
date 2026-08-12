<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;

class RecordAdministrativeDebtSettlementAuditLog
{
    use RecordsAuditSafely;

    public function handle(AdministrativeDebtSettlementCreated $event): void
    {
        $settlement = $event->settlement;
        $projectName = $settlement->project?->name ?? '#'.$settlement->project_id;

        $this->record(
            AuditAction::Saved,
            ArabicLocale::trans('messages.audit_admin_debt_settlement_created', [
                'name' => $projectName,
                'amount' => $settlement->recoverable_amount,
            ]),
            subject: $settlement,
            properties: [
                'settlement_id' => $settlement->id,
                'project_id' => $settlement->project_id,
            ],
        );
    }
}
