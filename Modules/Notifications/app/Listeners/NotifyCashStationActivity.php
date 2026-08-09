<?php

namespace Modules\Notifications\Listeners;

use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Models\Notification;
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
            $isContribution = $settlement->contribution_type !== null;

            $this->notificationService->notifyActivity(
                __(
                    $isContribution
                        ? 'messages.notification_contribution_created_title'
                        : 'messages.notification_cash_station_settlement_created_title'
                ),
                __(
                    $isContribution
                        ? 'messages.notification_contribution_created_message'
                        : 'messages.notification_cash_station_settlement_created_message',
                    [
                        'amount' => $settlement->amount,
                        'month' => $settlement->month,
                        'year' => $settlement->year,
                        'type' => $settlement->contribution_type?->value ?? '',
                    ]
                ),
                [
                    'action' => $isContribution ? 'contribution_created' : 'settlement_created',
                    'settlement_id' => $settlement->id,
                    'year' => $settlement->year,
                    'month' => $settlement->month,
                    'contribution_type' => $settlement->contribution_type?->value,
                ],
                $settlement,
            );

            return;
        }

        if ($event instanceof CashStationSettlementDeleted) {
            $isContribution = $event->contributionType !== null;

            $this->notificationService->notifyActivity(
                __(
                    $isContribution
                        ? 'messages.notification_contribution_deleted_title'
                        : 'messages.notification_cash_station_settlement_deleted_title'
                ),
                __(
                    $isContribution
                        ? 'messages.notification_contribution_deleted_message'
                        : 'messages.notification_cash_station_settlement_deleted_message',
                    [
                        'month' => $event->month,
                        'year' => $event->year,
                    ]
                ),
                [
                    'action' => $isContribution ? 'contribution_deleted' : 'settlement_deleted',
                    'settlement_id' => $event->settlementId,
                    'year' => $event->year,
                    'month' => $event->month,
                    'contribution_type' => $event->contributionType,
                ],
            );

            return;
        }

        $carry = $event->carry;
        $carryId = (int) $carry->id;

        if ($this->carryAlreadyNotified($carryId, (int) $carry->from_year, (int) $carry->from_month)) {
            return;
        }

        $executedAt = $carry->updated_at ?? $carry->created_at ?? now();

        $this->notificationService->notifyInfo(
            __('messages.notification_cash_station_carried_forward_title'),
            __('messages.notification_cash_station_carried_forward_message', [
                'month' => $carry->from_month,
                'year' => $carry->from_year,
                'executed_at' => $executedAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            ]),
            [
                'action' => 'carried_forward',
                'carry_id' => $carryId,
                'from_year' => $carry->from_year,
                'from_month' => $carry->from_month,
                'to_year' => $carry->to_year,
                'to_month' => $carry->to_month,
                'executed_at' => $executedAt->toISOString(),
            ],
            $carry,
        );
    }

    protected function carryAlreadyNotified(int $carryId, int $fromYear, int $fromMonth): bool
    {
        return Notification::query()
            ->where('type', NotificationType::Info->value)
            ->get()
            ->contains(function (Notification $notification) use ($carryId, $fromYear, $fromMonth): bool {
                $meta = is_array($notification->meta) ? $notification->meta : [];

                if (($meta['action'] ?? null) !== 'carried_forward') {
                    return false;
                }

                if (isset($meta['carry_id']) && (int) $meta['carry_id'] === $carryId) {
                    return true;
                }

                return (int) ($meta['from_year'] ?? 0) === $fromYear
                    && (int) ($meta['from_month'] ?? 0) === $fromMonth;
            });
    }
}
