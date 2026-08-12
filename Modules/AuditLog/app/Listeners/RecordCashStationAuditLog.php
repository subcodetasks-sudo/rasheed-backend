<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;

class RecordCashStationAuditLog
{
    use RecordsAuditSafely;

    public function handle(
        CashStationSettlementCreated|CashStationSettlementDeleted|CashStationCarriedForward $event,
    ): void {
        if ($event instanceof CashStationSettlementCreated) {
            $settlement = $event->settlement;
            $isContribution = $settlement->contribution_type !== null;
            $action = $isContribution ? AuditAction::Contribution : AuditAction::Transfer;
            $key = $isContribution ? 'audit_contribution_created' : 'audit_transfer_created';

            $this->record(
                $action,
                ArabicLocale::trans("messages.{$key}", [
                    'amount' => $settlement->amount,
                    'month' => $settlement->month,
                    'year' => $settlement->year,
                ]),
                subject: $settlement,
                properties: [
                    'settlement_id' => $settlement->id,
                    'year' => $settlement->year,
                    'month' => $settlement->month,
                    'contribution_type' => $settlement->contribution_type?->value,
                ],
            );

            return;
        }

        if ($event instanceof CashStationSettlementDeleted) {
            $isContribution = $event->contributionType !== null;
            $action = $isContribution ? AuditAction::Contribution : AuditAction::Transfer;
            $key = $isContribution ? 'audit_contribution_deleted' : 'audit_transfer_deleted';

            $this->record(
                $action,
                ArabicLocale::trans("messages.{$key}", [
                    'month' => $event->month,
                    'year' => $event->year,
                ]),
                properties: [
                    'settlement_id' => $event->settlementId,
                    'year' => $event->year,
                    'month' => $event->month,
                    'contribution_type' => $event->contributionType,
                ],
            );

            return;
        }

        $carry = $event->carry;

        $this->record(
            AuditAction::CarriedForward,
            ArabicLocale::trans('messages.audit_cash_station_carried_forward', [
                'month' => $carry->from_month,
                'year' => $carry->from_year,
            ]),
            subject: $carry,
            properties: [
                'carry_id' => $carry->id,
                'from_year' => $carry->from_year,
                'from_month' => $carry->from_month,
                'to_year' => $carry->to_year,
                'to_month' => $carry->to_month,
            ],
        );
    }
}
