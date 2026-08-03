<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;

class RefreshAdministrativeDebtSettlementOnDailyJournalUpdate
{
    public function __construct(
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    public function handle(DailyJournalUpdated|AdministrativeDebtRepaid $event): void
    {
        if ($event instanceof DailyJournalUpdated) {
            $year = (int) $event->journalDate->year;
            $month = (int) $event->journalDate->month;
        } else {
            $date = Carbon::parse($event->entry->journal_date);
            $year = (int) $date->year;
            $month = (int) $date->month;
        }

        $payload = $this->buildAdministrativeDebtSettlementAction->execute($month, $year);

        AdministrativeDebtSettlementUpdated::dispatch($year, $month, $payload);
    }
}
