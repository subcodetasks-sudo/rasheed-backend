<?php

namespace Modules\AuditLog\Listeners;

use App\Support\ArabicLocale;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Support\RecordsAuditSafely;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;

class RecordDailyJournalAuditLog
{
    use RecordsAuditSafely;

    public function handle(DailyJournalUpdated|AdministrativeDebtRepaid $event): void
    {
        if ($event instanceof DailyJournalUpdated) {
            if (! $this->isExactMutatingPath('api/v1/daily-journals', ['PUT', 'PATCH'])) {
                return;
            }

            $date = $event->journalDate->toDateString();

            $this->record(
                AuditAction::Saved,
                ArabicLocale::trans('messages.audit_daily_journal_saved', ['date' => $date]),
                properties: [
                    'journal_date' => $date,
                    'entries_count' => $event->entries->count(),
                ],
            );

            return;
        }

        $date = $event->entry->journal_date?->toDateString() ?? (string) $event->entry->journal_date;

        $this->record(
            AuditAction::Repaid,
            ArabicLocale::trans('messages.audit_admin_debt_repaid', [
                'project_id' => $event->entry->project_id,
                'date' => $date,
            ]),
            subject: $event->entry,
            properties: [
                'journal_date' => $date,
                'project_id' => $event->entry->project_id,
                'entry_id' => $event->entry->id,
            ],
        );
    }
}
