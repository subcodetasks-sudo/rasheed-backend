<?php

namespace Modules\DailyJournal\Actions;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\CashStation\Actions\AssertFinancialMonthUnlockedAction;
use Modules\DailyJournal\Models\DailyJournalEntry;

class RecalculateDailyJournalForwardChainAction
{
    public function __construct(
        private readonly RecalculateDailyJournalAction $recalculateDailyJournalAction,
        private readonly AssertFinancialMonthUnlockedAction $assertFinancialMonthUnlockedAction,
    ) {}

    /**
     * Recalculate every journal date strictly after `$fromDate` (ascending).
     * Call after `$fromDate` itself has already been recalculated.
     * Dates in locked financial months are skipped so frozen months stay immutable.
     */
    public function execute(CarbonInterface $fromDate): void
    {
        $dates = DailyJournalEntry::query()
            ->whereDate('journal_date', '>', $fromDate->toDateString())
            ->distinct()
            ->orderBy('journal_date')
            ->pluck('journal_date');

        foreach ($dates as $date) {
            $carbon = Carbon::parse($date)->startOfDay();

            if ($this->assertFinancialMonthUnlockedAction->isDateLocked($carbon)) {
                continue;
            }

            $this->recalculateDailyJournalAction->execute($carbon);
        }
    }
}
