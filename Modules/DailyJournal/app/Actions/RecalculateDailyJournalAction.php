<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Modules\DailyJournal\DTOs\DailyJournalRecalculationResult;
use Modules\DailyJournal\Models\DailyJournalEntry;

class RecalculateDailyJournalAction
{
    public function __construct(
        private readonly EnsureDailyJournalEntriesForActiveProjectsAction $ensureEntriesAction,
        private readonly CalculateAdministrativeFeesAction $calculateAdministrativeFeesAction,
        private readonly CalculateOperationalDeductionsAction $calculateOperationalDeductionsAction,
        private readonly ResolveAdministrativeExpenseAction $resolveAdministrativeExpenseAction,
        private readonly CalculateDailyTotalsAction $calculateDailyTotalsAction,
        private readonly CalculateFundBalancesAction $calculateFundBalancesAction,
        private readonly CalculateAdministrativeDebtAction $calculateAdministrativeDebtAction,
        private readonly UpdateAccumulatedAdministrativeDebtAction $updateAccumulatedAdministrativeDebtAction,
        private readonly PersistCalculatedDailyJournalFieldsAction $persistCalculatedFieldsAction,
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
        $entries = $this->calculateFundBalancesAction->execute($entries, $date);

        // Remaining Deficit = abs(negative signed fund balance) for contribution validation.
        $remainingDeficits = [];
        foreach ($entries as $entry) {
            /** @var DailyJournalEntry $entry */
            $balance = (float) $entry->fund_balance;
            $remainingDeficits[$entry->project_id] = $balance < 0 ? abs($balance) : 0.0;
        }

        $entries = $this->calculateAdministrativeDebtAction->execute($entries);
        $entries = $this->updateAccumulatedAdministrativeDebtAction->execute($entries, $date);
        $entries = $this->persistCalculatedFieldsAction->execute($entries);

        $this->updateSummariesAction->execute($date);
        $this->updateReportsAction->execute($date);

        return new DailyJournalRecalculationResult(
            entries: $entries->loadMissing(['project.category']),
            remainingDeficits: $remainingDeficits,
        );
    }
}
