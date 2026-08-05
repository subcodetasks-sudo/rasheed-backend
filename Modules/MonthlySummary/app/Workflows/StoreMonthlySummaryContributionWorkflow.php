<?php

namespace Modules\MonthlySummary\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Actions\CreateCashStationSettlementAction;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\MonthlySummary\Actions\ApplyContributionAdministrativeDebtAction;
use Modules\MonthlySummary\Actions\ApplyContributionFundBalanceAction;
use Modules\MonthlySummary\Actions\BroadcastContributionJournalTipAction;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Actions\ValidateMonthlySummaryContributionAction;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;

class StoreMonthlySummaryContributionWorkflow
{
    public function __construct(
        private readonly ValidateMonthlySummaryContributionAction $validateMonthlySummaryContributionAction,
        private readonly CreateCashStationSettlementAction $createCashStationSettlementAction,
        private readonly ApplyContributionAdministrativeDebtAction $applyContributionAdministrativeDebtAction,
        private readonly ApplyContributionFundBalanceAction $applyContributionFundBalanceAction,
        private readonly BroadcastContributionJournalTipAction $broadcastContributionJournalTipAction,
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    public function handle(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        ContributionType $contributionType,
        float $amount,
    ): CashStationSettlement {
        $settlement = DB::transaction(function () use (
            $year,
            $month,
            $fromProjectId,
            $toProjectId,
            $contributionType,
            $amount,
        ) {
            $this->validateMonthlySummaryContributionAction->execute(
                $year,
                $month,
                $fromProjectId,
                $toProjectId,
                $contributionType,
                $amount,
            );

            $settlement = $this->createCashStationSettlementAction->execute(
                $year,
                $month,
                $fromProjectId,
                $toProjectId,
                number_format($amount, 2, '.', ''),
                auth()->user()?->uuid,
                $contributionType,
            );

            if ($contributionType === ContributionType::AdministrativeDebt) {
                $this->applyContributionAdministrativeDebtAction->execute(
                    $toProjectId,
                    $year,
                    $month,
                    $amount,
                );
            } elseif ($contributionType === ContributionType::FundDeficit) {
                $this->applyContributionFundBalanceAction->execute(
                    $toProjectId,
                    $year,
                    $month,
                    $amount,
                );
            }

            return $settlement;
        });

        CashStationSettlementCreated::dispatch($settlement);
        CashStationUpdated::dispatch(
            $settlement->year,
            $settlement->month,
            $this->buildCashStationAction->execute($settlement->month, $settlement->year),
        );
        MonthlySummaryUpdated::dispatch(
            $settlement->year,
            $settlement->month,
            $this->buildMonthlySummaryAction->execute($settlement->month, $settlement->year),
        );
        AdministrativeDebtSettlementUpdated::dispatch(
            $settlement->year,
            $settlement->month,
            $this->buildAdministrativeDebtSettlementAction->execute($settlement->month, $settlement->year),
        );

        if (in_array($contributionType, [
            ContributionType::AdministrativeDebt,
            ContributionType::FundDeficit,
        ], true)) {
            $this->broadcastContributionJournalTipAction->execute(
                $toProjectId,
                $settlement->year,
                $settlement->month,
            );
        }

        return $settlement;
    }
}
