<?php

namespace Modules\MonthlySummary\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Actions\CreateCashStationSettlementAction;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\MonthlySummary\Actions\ApplyContributionAdministrativeDebtAction;
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
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
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

        return $settlement;
    }
}
