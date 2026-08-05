<?php

namespace Modules\Notifications\Listeners;

use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\MonthlySummary\Actions\ApplyContributionAdministrativeDebtAction;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;

/**
 * A contribution that reduces administrative debt only ever touches journal entries dated
 * on/after the day it was recorded (see ApplyContributionAdministrativeDebtAction). If none
 * existed yet at that time, the settlement is left pending (`journal_anchor_date` null). Once a
 * qualifying entry shows up — i.e. this event fires for one of the pending beneficiary projects —
 * retry applying it here so the reduction is never silently lost.
 */
class ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate
{
    public function __construct(
        private readonly ApplyContributionAdministrativeDebtAction $applyContributionAdministrativeDebtAction,
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    public function handle(DailyJournalUpdated $event): void
    {
        $projectIds = $event->entries->pluck('project_id')->unique()->values()->all();

        if ($projectIds === []) {
            return;
        }

        $pending = CashStationSettlement::query()
            ->where('contribution_type', ContributionType::AdministrativeDebt)
            ->whereNull('journal_anchor_date')
            ->whereIn('to_project_id', $projectIds)
            ->get();

        foreach ($pending as $settlement) {
            $this->applyContributionAdministrativeDebtAction->execute($settlement);

            if ($settlement->journal_anchor_date !== null) {
                $this->broadcastMonth((int) $settlement->year, (int) $settlement->month);
            }
        }
    }

    private function broadcastMonth(int $year, int $month): void
    {
        CashStationUpdated::dispatch($year, $month, $this->buildCashStationAction->execute($month, $year));
        MonthlySummaryUpdated::dispatch($year, $month, $this->buildMonthlySummaryAction->execute($month, $year));
        AdministrativeDebtSettlementUpdated::dispatch(
            $year,
            $month,
            $this->buildAdministrativeDebtSettlementAction->execute($month, $year),
        );
    }
}
