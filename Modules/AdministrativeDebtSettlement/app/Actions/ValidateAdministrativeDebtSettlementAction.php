<?php

namespace Modules\AdministrativeDebtSettlement\Actions;

use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\Project\Exceptions\BusinessException;
use Modules\Project\Models\Project;

class ValidateAdministrativeDebtSettlementAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    /**
     * @return array{
     *     amount: float,
     *     snapshot: array<string, mixed>,
     *     project_row: array<string, mixed>
     * }
     */
    public function execute(int $year, int $month, int $projectId, ?float $amount): array
    {
        $project = Project::query()->active()->find($projectId);
        if ($project === null) {
            throw new BusinessException(__('messages.administrative_debt_settlement_project_not_found'), 404);
        }

        $cashStation = $this->buildCashStationAction->execute($month, $year);
        $projectRow = collect($cashStation['projects'])->firstWhere('project_id', $projectId);
        if ($projectRow === null) {
            throw new BusinessException(__('messages.administrative_debt_settlement_project_not_found'), 404);
        }

        $settlements = $this->buildAdministrativeDebtSettlementAction
            ->settlementsGroupedByProject([$projectId])[$projectId] ?? collect();

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-d', strtotime('last day of '.$start));
        $previousEnd = date('Y-m-d', strtotime('last day of '.$start.' -1 month'));

        $currentSums = $this->buildAdministrativeDebtSettlementAction->currentMonthAdministrativeDebtSums(
            [$projectId],
            $start,
            $end,
        );
        $priorAccumulated = $this->buildAdministrativeDebtSettlementAction->accumulatedAsOf([$projectId], $previousEnd);
        $monthEndAccumulated = $this->buildAdministrativeDebtSettlementAction->accumulatedAsOf([$projectId], $end);

        $snapshot = $this->buildAdministrativeDebtSettlementAction->projectDebtSnapshot(
            $projectId,
            $year,
            $month,
            (float) $projectRow['net_cash_fund'],
            (float) $projectRow['previous_monthly_total'],
            (float) $projectRow['monthly_total'],
            (float) $projectRow['added_contribution'],
            (float) $projectRow['deducted_contribution'],
            $settlements,
            (float) ($currentSums[$projectId] ?? 0),
            (float) ($priorAccumulated[$projectId] ?? 0),
            (float) ($monthEndAccumulated[$projectId] ?? 0),
        );

        if ($snapshot['administrative_debt'] <= 0) {
            throw new BusinessException(__('messages.administrative_debt_settlement_no_debt'));
        }

        if ($snapshot['surplus'] <= 0) {
            throw new BusinessException(__('messages.administrative_debt_settlement_requires_surplus'));
        }

        $resolvedAmount = $amount === null
            ? $snapshot['recoverable_amount']
            : round($amount, 2);

        if ($resolvedAmount <= 0) {
            throw new BusinessException(__('messages.administrative_debt_settlement_amount_invalid'));
        }

        $maxUseful = round(min(
            (float) $snapshot['surplus'],
            (float) $snapshot['administrative_debt'] + (float) $snapshot['cash_box_capacity'],
        ), 2);

        if ($resolvedAmount > $maxUseful + 0.001) {
            if ($resolvedAmount > (float) $snapshot['surplus'] + 0.001) {
                throw new BusinessException(__('messages.administrative_debt_settlement_exceeds_surplus'));
            }

            throw new BusinessException(__('messages.administrative_debt_settlement_exceeds_debt'));
        }

        return [
            'amount' => $resolvedAmount,
            'snapshot' => $snapshot,
            'project_row' => $projectRow,
        ];
    }
}
