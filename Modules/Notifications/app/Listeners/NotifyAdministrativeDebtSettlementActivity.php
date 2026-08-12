<?php

namespace Modules\Notifications\Listeners;

use App\Support\ArabicLocale;
use Carbon\Carbon;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\DailyJournal\Actions\ReadAccumulatedAdministrativeDebtTipAction;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Services\NotificationService;
use Modules\Project\Models\Project;

class NotifyAdministrativeDebtSettlementActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ReadAccumulatedAdministrativeDebtTipAction $readAccumulatedAdministrativeDebtTipAction,
    ) {}

    public function handle(AdministrativeDebtSettlementCreated $event): void
    {
        $settlement = $event->settlement;
        $settlementId = (int) $settlement->id;

        if ($this->settlementAlreadyNotified($settlementId)) {
            return;
        }

        $project = Project::query()->withTrashed()->find($settlement->project_id);
        $projectName = $project?->name ?? ('#'.$settlement->project_id);
        $paidAmount = round((float) $settlement->recoverable_amount, 2);

        $asOfDate = Carbon::create(
            (int) $settlement->year,
            (int) $settlement->month,
            1,
        )->endOfMonth()->toDateString();

        $debts = $this->readAccumulatedAdministrativeDebtTipAction->execute(
            [(int) $settlement->project_id],
            $asOfDate,
        );
        $remainingDebt = round((float) ($debts[(int) $settlement->project_id] ?? 0), 2);

        $this->notificationService->notifyInfo(
            ArabicLocale::trans('messages.notification_admin_debt_settlement_created_title'),
            ArabicLocale::trans('messages.notification_admin_debt_settlement_created_message', [
                'name' => $projectName,
                'amount' => number_format($paidAmount, 2, '.', ','),
                'remaining' => number_format($remainingDebt, 2, '.', ','),
            ]),
            [
                'action' => 'admin_debt_settlement_created',
                'settlement_id' => $settlementId,
                'project_id' => (int) $settlement->project_id,
                'month' => $settlement->month,
                'year' => $settlement->year,
                'paid_amount' => $paidAmount,
                'remaining_debt' => $remainingDebt,
            ],
            $settlement,
        );
    }

    protected function settlementAlreadyNotified(int $settlementId): bool
    {
        return Notification::query()
            ->where('type', NotificationType::Info->value)
            ->get()
            ->contains(function (Notification $notification) use ($settlementId): bool {
                $meta = is_array($notification->meta) ? $notification->meta : [];

                return ($meta['action'] ?? null) === 'admin_debt_settlement_created'
                    && (int) ($meta['settlement_id'] ?? 0) === $settlementId;
            });
    }
}
