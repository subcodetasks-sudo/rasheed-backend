<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Services\DailyJournalCalculationService;

class UpdateAccumulatedAdministrativeDebtAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    public function execute(Collection $entries, CarbonInterface $date): Collection
    {
        $previous = $this->calculationService->previousBalances(
            $entries->pluck('project_id')->all(),
            $date
        );

        return $this->calculationService->applyAccumulatedAdministrativeDebt($entries, $previous);
    }
}
