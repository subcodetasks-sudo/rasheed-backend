<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Modules\DailyJournal\DTOs\DailyJournalRecalculationResult;

class RecalculateDailyJournalAction
{
    public function __construct(
        private readonly EnsureDailyJournalEntriesForActiveProjectsAction $ensureEntriesAction,
        private readonly CalculateAdministrativeFeesAction $calculateAdministrativeFeesAction,
        private readonly CalculateOperationalDeductionsAction $calculateOperationalDeductionsAction,
        private readonly ResolveAdministrativeExpenseAction $resolveAdministrativeExpenseAction,
        private readonly CalculateDailyTotalsAction $calculateDailyTotalsAction,
        private readonly ApplyFundBalanceDebtAndReliefPipelineAction $applyFundBalanceDebtAndReliefPipelineAction,
        private readonly UpdateAccumulatedAdministrativeDebtAction $updateAccumulatedAdministrativeDebtAction,
        private readonly PersistCalculatedDailyJournalFieldsAction $persistCalculatedFieldsAction,
        private readonly ReapplyDailyJournalOverlaysAction $reapplyDailyJournalOverlaysAction,
        private readonly UpdateDailyJournalSummariesAction $updateSummariesAction,
        private readonly UpdateDailyJournalReportsAction $updateReportsAction,
    ) {}

    /**
     * @param  bool  $preserveIncomeDerivedDeductions  When true (contribution Pass 2), skip fee/op/expense recalc.
     */
    public function execute(
        CarbonInterface $date,
        bool $preserveIncomeDerivedDeductions = false
    ): DailyJournalRecalculationResult {
        $entries = $this->ensureEntriesAction->execute($date);

        if (! $preserveIncomeDerivedDeductions) {
            $entries = $this->calculateAdministrativeFeesAction->execute($entries);
            $entries = $this->calculateOperationalDeductionsAction->execute($entries);
            $entries = $this->resolveAdministrativeExpenseAction->execute($entries);
        }

        $entries = $this->calculateDailyTotalsAction->execute($entries);

        $pipelineResult = $this->applyFundBalanceDebtAndReliefPipelineAction->execute($entries, $date);
        $entries = $pipelineResult['entries'];
        $remainingDeficits = $pipelineResult['remainingDeficits'];

        $entries = $this->updateAccumulatedAdministrativeDebtAction->execute($entries, $date);
        $entries = $this->persistCalculatedFieldsAction->execute($entries);
        $entries = $this->reapplyDailyJournalOverlaysAction->execute($entries, $date);

        $this->updateSummariesAction->execute($date);
        $this->updateReportsAction->execute($date);

        return new DailyJournalRecalculationResult(
            entries: $entries->loadMissing(['project.category']),
            remainingDeficits: $remainingDeficits,
        );
    }
}
