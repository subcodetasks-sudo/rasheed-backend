<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\AdminPercentagePoolReliefAllocation;
use Modules\DailyJournal\Services\DailyJournalCalculationService;

class ApplyAdministrativePoolReliefAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    public function execute(Collection $entries, CarbonInterface $date): Collection
    {
        $dateString = $date->toDateString();

        AdminPercentagePoolReliefAllocation::query()
            ->whereDate('journal_date', $dateString)
            ->delete();

        $result = $this->calculationService->applyAdministrativePoolRelief($entries);

        foreach ($result['allocations'] as $allocation) {
            AdminPercentagePoolReliefAllocation::query()->create([
                ...$allocation,
                'journal_date' => $dateString,
            ]);
        }

        return $result['entries'];
    }
}
