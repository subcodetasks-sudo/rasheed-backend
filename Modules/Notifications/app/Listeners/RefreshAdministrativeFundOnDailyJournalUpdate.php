<?php

namespace Modules\Notifications\Listeners;

use Modules\AdministrativeFund\Actions\BuildAdministrativeFundAction;
use Modules\AdministrativeFund\Events\AdministrativeFundUpdated;
use Modules\DailyJournal\Events\DailyJournalUpdated;

class RefreshAdministrativeFundOnDailyJournalUpdate
{
    public function __construct(
        private readonly BuildAdministrativeFundAction $buildAdministrativeFundAction,
    ) {}

    public function handle(DailyJournalUpdated $event): void
    {
        $year = (int) $event->journalDate->year;
        $month = (int) $event->journalDate->month;

        $payload = $this->buildAdministrativeFundAction->execute($month, $year);

        AdministrativeFundUpdated::dispatch($year, $month, $payload);
    }
}
