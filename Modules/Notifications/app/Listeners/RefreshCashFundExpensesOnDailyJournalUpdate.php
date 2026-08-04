<?php

namespace Modules\Notifications\Listeners;

use Modules\CashFundExpenses\Actions\BuildCashFundExpensesAction;
use Modules\CashFundExpenses\Events\CashFundExpensesUpdated;
use Modules\DailyJournal\Events\DailyJournalUpdated;

class RefreshCashFundExpensesOnDailyJournalUpdate
{
    public function __construct(
        private readonly BuildCashFundExpensesAction $buildCashFundExpensesAction,
    ) {}

    public function handle(DailyJournalUpdated $event): void
    {
        $year = (int) $event->journalDate->year;
        $month = (int) $event->journalDate->month;

        $payload = $this->buildCashFundExpensesAction->execute($month, $year);

        CashFundExpensesUpdated::dispatch($year, $month, $payload);
    }
}
