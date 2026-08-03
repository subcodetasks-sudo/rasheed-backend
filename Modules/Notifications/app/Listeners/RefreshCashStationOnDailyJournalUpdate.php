<?php

namespace Modules\Notifications\Listeners;

use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\DailyJournal\Events\DailyJournalUpdated;

class RefreshCashStationOnDailyJournalUpdate
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function handle(DailyJournalUpdated $event): void
    {
        $year = (int) $event->journalDate->year;
        $month = (int) $event->journalDate->month;

        $this->broadcastMonth($year, $month);

        $fromYear = $year;
        $fromMonth = $month;

        while (true) {
            $carry = CashStationMonthCarry::query()
                ->where('from_year', $fromYear)
                ->where('from_month', $fromMonth)
                ->first();

            if ($carry === null) {
                break;
            }

            $this->broadcastMonth((int) $carry->to_year, (int) $carry->to_month);

            $fromYear = (int) $carry->to_year;
            $fromMonth = (int) $carry->to_month;
        }
    }

    private function broadcastMonth(int $year, int $month): void
    {
        $payload = $this->buildCashStationAction->execute($month, $year);

        CashStationUpdated::dispatch($year, $month, $payload);
    }
}
