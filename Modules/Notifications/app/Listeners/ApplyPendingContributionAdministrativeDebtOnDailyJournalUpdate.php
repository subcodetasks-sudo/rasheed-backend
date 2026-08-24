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
 * Administrative-debt contributions reduce beneficiary debt and debit contributor
 * fund_balance. Either side may stay pending until a qualifying tip exists.
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
            ->where(function ($query) use ($projectIds) {
                $query->where(function ($toSide) use ($projectIds) {
                    $toSide->whereNull('journal_anchor_date')
                        ->whereIn('to_project_id', $projectIds);
                })->orWhere(function ($fromSide) use ($projectIds) {
                    $fromSide->whereNull('from_journal_anchor_date')
                        ->whereIn('from_project_id', $projectIds);
                });
            })
            ->get();

        foreach ($pending as $settlement) {
            $hadToAnchor = $settlement->journal_anchor_date !== null;
            $hadFromAnchor = $settlement->from_journal_anchor_date !== null;

            $this->applyContributionAdministrativeDebtAction->execute($settlement);
            $settlement->refresh();

            if (
                (! $hadToAnchor && $settlement->journal_anchor_date !== null)
                || (! $hadFromAnchor && $settlement->from_journal_anchor_date !== null)
            ) {
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
