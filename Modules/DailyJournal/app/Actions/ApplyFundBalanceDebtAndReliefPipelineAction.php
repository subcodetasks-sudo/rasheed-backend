<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\AdminPercentagePoolReliefAllocation;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\DailyJournalCalculationService;

class ApplyFundBalanceDebtAndReliefPipelineAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     * @return array{entries: Collection<int, DailyJournalEntry>, remainingDeficits: array<int, float>}
     */
    public function execute(Collection $entries, CarbonInterface $date): array
    {
        $dateString = $date->toDateString();

        AdminPercentagePoolReliefAllocation::query()
            ->whereDate('journal_date', $dateString)
            ->delete();

        $previousBalances = $this->calculationService->previousBalances(
            $entries->pluck('project_id')->unique()->values()->all(),
            $date,
        );

        $result = $this->calculationService->applyFundBalanceDebtAndReliefPipeline(
            $entries,
            $previousBalances,
        );

        foreach ($result['allocations'] as $allocation) {
            AdminPercentagePoolReliefAllocation::query()->create([
                ...$allocation,
                'journal_date' => $dateString,
            ]);
        }

        return [
            'entries' => $result['entries'],
            'remainingDeficits' => $result['remainingDeficits'],
        ];
    }
}
