<?php

namespace Modules\MonthlySummary\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\MonthlySummary\Actions\BroadcastContributionJournalTipAction;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;
use Modules\MonthlySummary\Actions\ReverseContributionAdministrativeDebtAction;
use Modules\MonthlySummary\Actions\ReverseContributionFundBalanceAction;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;
use Modules\Project\Exceptions\BusinessException;

class CancelMonthlySummaryContributionWorkflow
{
    public function __construct(
        private readonly ReverseContributionAdministrativeDebtAction $reverseContributionAdministrativeDebtAction,
        private readonly ReverseContributionFundBalanceAction $reverseContributionFundBalanceAction,
        private readonly BroadcastContributionJournalTipAction $broadcastContributionJournalTipAction,
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    public function handle(int $settlementId): array
    {
        $result = DB::transaction(function () use ($settlementId) {
            $settlement = CashStationSettlement::query()
                ->lockForUpdate()
                ->find($settlementId);

            if ($settlement === null) {
                throw new BusinessException(__('messages.monthly_summary_contribution_not_found'), 404);
            }

            if ($settlement->contribution_type === null) {
                throw new BusinessException(__('messages.monthly_summary_contribution_not_found'), 404);
            }

            $year = $settlement->year;
            $month = $settlement->month;
            $amount = (float) $settlement->amount;
            $toProjectId = $settlement->to_project_id;
            $type = $settlement->contribution_type;

            if ($type === ContributionType::AdministrativeDebt) {
                $this->reverseContributionAdministrativeDebtAction->execute($settlement);
            } elseif ($type === ContributionType::FundDeficit) {
                $this->reverseContributionFundBalanceAction->execute($settlement);
            }

            $settlement->delete();

            return [
                'settlement_id' => $settlementId,
                'year' => $year,
                'month' => $month,
                'to_project_id' => $toProjectId,
                'contribution_type' => $type,
            ];
        });

        CashStationSettlementDeleted::dispatch(
            $result['settlement_id'],
            $result['year'],
            $result['month'],
            $result['contribution_type'] instanceof ContributionType
                ? $result['contribution_type']->value
                : null,
            (int) $result['to_project_id'],
        );
        CashStationUpdated::dispatch(
            $result['year'],
            $result['month'],
            $this->buildCashStationAction->execute($result['month'], $result['year']),
        );
        MonthlySummaryUpdated::dispatch(
            $result['year'],
            $result['month'],
            $this->buildMonthlySummaryAction->execute($result['month'], $result['year']),
        );
        AdministrativeDebtSettlementUpdated::dispatch(
            $result['year'],
            $result['month'],
            $this->buildAdministrativeDebtSettlementAction->execute($result['month'], $result['year']),
        );

        if (in_array($result['contribution_type'], [
            ContributionType::AdministrativeDebt,
            ContributionType::FundDeficit,
        ], true)) {
            $this->broadcastContributionJournalTipAction->execute(
                $result['to_project_id'],
                $result['year'],
                $result['month'],
            );
        }

        return $result;
    }
}
