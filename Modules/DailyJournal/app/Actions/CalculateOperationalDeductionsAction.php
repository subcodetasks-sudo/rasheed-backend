<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Support\Collection;
use Modules\DailyJournal\Services\DailyJournalCalculationService;

class CalculateOperationalDeductionsAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    public function execute(Collection $entries): Collection
    {
        return $this->calculationService->applyOperationalDeductions($entries);
    }
}
