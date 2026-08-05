<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;

class RefreshMonthlySummaryOnDailyJournalUpdate
{
    public function __construct(
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
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
        $payload = $this->buildMonthlySummaryAction->execute($month, $year);

        MonthlySummaryUpdated::dispatch($year, $month, $payload);
    }
}
