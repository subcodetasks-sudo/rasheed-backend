<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\DailyJournalCalculationService;

class CalculateAdministrativeExpenseCoverageAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return Collection<int, DailyJournalEntry>
     */
    public function execute(Collection $entries, CarbonInterface $date): Collection
    {
        $previousBalances = $this->calculationService->previousBalances(
            $entries->pluck('project_id')->unique()->values()->all(),
            $date,
        );

        return $this->calculationService->applyAdministrativeExpenseCoverage($entries, $previousBalances);
    }
}
