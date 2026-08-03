<?php

namespace Modules\Notifications\Listeners;

use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\Notifications\Services\NotificationService;

class NotifyCashStationActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(
        CashStationSettlementCreated|CashStationSettlementDeleted|CashStationCarriedForward $event,
    ): void {
        if ($event instanceof CashStationSettlementCreated) {
            $settlement = $event->settlement;

            $this->notificationService->notifyActivity(
                __('messages.notification_cash_station_settlement_created_title'),
                __('messages.notification_cash_station_settlement_created_message', [
                    'amount' => $settlement->amount,
                    'month' => $settlement->month,
                    'year' => $settlement->year,
                ]),
                [
                    'action' => 'settlement_created',
                    'settlement_id' => $settlement->id,
                    'year' => $settlement->year,
                    'month' => $settlement->month,
                ],
                $settlement,
            );

            return;
        }

        if ($event instanceof CashStationSettlementDeleted) {
            $this->notificationService->notifyActivity(
                __('messages.notification_cash_station_settlement_deleted_title'),
                __('messages.notification_cash_station_settlement_deleted_message', [
                    'month' => $event->month,
                    'year' => $event->year,
                ]),
                [
                    'action' => 'settlement_deleted',
                    'settlement_id' => $event->settlementId,
                    'year' => $event->year,
                    'month' => $event->month,
                ],
            );

            return;
        }

        $carry = $event->carry;

        $this->notificationService->notifyActivity(
            __('messages.notification_cash_station_carried_forward_title'),
            __('messages.notification_cash_station_carried_forward_message', [
                'from_month' => $carry->from_month,
                'from_year' => $carry->from_year,
                'to_month' => $carry->to_month,
                'to_year' => $carry->to_year,
            ]),
            [
                'action' => 'carried_forward',
                'from_year' => $carry->from_year,
                'from_month' => $carry->from_month,
                'to_year' => $carry->to_year,
                'to_month' => $carry->to_month,
            ],
            $carry,
        );
    }
}
