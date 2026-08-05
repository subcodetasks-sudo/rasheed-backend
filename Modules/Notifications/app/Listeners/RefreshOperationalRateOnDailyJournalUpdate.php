<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\OperationalRate\Actions\BuildOperationalRateAction;
use Modules\OperationalRate\Events\OperationalRateUpdated;

class RefreshOperationalRateOnDailyJournalUpdate
{
    public function __construct(
        private readonly BuildOperationalRateAction $buildOperationalRateAction,
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

        $payload = $this->buildOperationalRateAction->execute($month, $year);

        OperationalRateUpdated::dispatch($year, $month, $payload);
    }
}
