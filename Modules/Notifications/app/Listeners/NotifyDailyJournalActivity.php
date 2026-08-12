<?php

namespace Modules\Notifications\Listeners;

use App\Support\ArabicLocale;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Notifications\Services\NotificationService;

class NotifyDailyJournalActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(DailyJournalUpdated|AdministrativeDebtRepaid $event): void
    {
        if ($event instanceof DailyJournalUpdated) {
            $date = $event->journalDate->toDateString();

            $this->notificationService->notifyActivity(
                ArabicLocale::trans('messages.notification_daily_journal_updated_title'),
                ArabicLocale::trans('messages.notification_daily_journal_updated_message', ['date' => $date]),
                [
                    'action' => 'updated',
                    'journal_date' => $date,
                    'entries_count' => $event->entries->count(),
                ],
            );

            return;
        }

        $date = $event->entry->journal_date?->toDateString() ?? (string) $event->entry->journal_date;

        $this->notificationService->notifySuccess(
            ArabicLocale::trans('messages.notification_admin_debt_repaid_title'),
            ArabicLocale::trans('messages.notification_admin_debt_repaid_message', [
                'project_id' => $event->entry->project_id,
                'date' => $date,
            ]),
            [
                'action' => 'admin_debt_repaid',
                'journal_date' => $date,
                'project_id' => $event->entry->project_id,
                'entry_id' => $event->entry->id,
            ],
            $event->entry,
        );
    }
}
