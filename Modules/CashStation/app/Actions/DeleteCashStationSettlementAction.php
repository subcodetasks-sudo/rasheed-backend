<?php

namespace Modules\CashStation\Actions;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\MonthlySummary\Actions\ReverseContributionAdministrativeDebtAction;
use Modules\MonthlySummary\Actions\ReverseContributionFundBalanceAction;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Project\Exceptions\BusinessException;

class DeleteCashStationSettlementAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly ReverseContributionAdministrativeDebtAction $reverseContributionAdministrativeDebtAction,
        private readonly ReverseContributionFundBalanceAction $reverseContributionFundBalanceAction,
    ) {}

    /**
     * @return array{year: int, month: int}
     */
    public function execute(int $settlementId): array
    {
        $settlement = CashStationSettlement::query()->find($settlementId);

        if ($settlement === null) {
            throw new BusinessException(__('messages.cash_station_settlement_not_found'), 404);
        }

        $year = $settlement->year;
        $month = $settlement->month;
        $contributionType = $settlement->contribution_type?->value;
        $toProjectId = $settlement->contribution_type !== null
            ? (int) $settlement->to_project_id
            : null;

        DB::transaction(function () use ($settlementId) {
            $locked = CashStationSettlement::query()
                ->lockForUpdate()
                ->find($settlementId);

            if ($locked === null) {
                return;
            }

            // If this settlement was a contribution, it may have mutated the Daily Journal
            // state; reversing must happen before the row is deleted.
            if ($locked->contribution_type !== null) {
                if ($locked->contribution_type === ContributionType::AdministrativeDebt) {
                    $this->reverseContributionAdministrativeDebtAction->execute($locked);
                } elseif ($locked->contribution_type === ContributionType::FundDeficit) {
                    $this->reverseContributionFundBalanceAction->execute($locked);
                }
            }

            $locked->delete();
        });

        CashStationSettlementDeleted::dispatch($settlementId, $year, $month, $contributionType, $toProjectId);
        CashStationUpdated::dispatch(
            $year,
            $month,
            $this->buildCashStationAction->execute($month, $year),
        );

        return [
            'year' => $year,
            'month' => $month,
        ];
    }
}
