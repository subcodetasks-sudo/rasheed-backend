<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Services\DailyJournalCalculationService;

class CalculateFundBalancesAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    public function execute(Collection $entries, CarbonInterface $date): Collection
    {
        $previousBalances = $this->calculationService->previousBalances(
            $entries->pluck('project_id')->all(),
            $date
        );

        return $this->calculationService->applyFundBalances($entries, $previousBalances);
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{fund_balance: float, accumulated_administrative_debt: float}>
     */
    public function previousBalances(array $projectIds, CarbonInterface $date): array
    {
        return $this->calculationService->previousBalances($projectIds, $date);
    }
}
