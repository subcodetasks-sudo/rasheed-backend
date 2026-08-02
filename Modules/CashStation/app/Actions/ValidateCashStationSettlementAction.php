<?php

namespace Modules\CashStation\Actions;

use Modules\Project\Exceptions\BusinessException;

class ValidateCashStationSettlementAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function execute(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        string $amount,
    ): void {
        if ($fromProjectId === $toProjectId) {
            throw new BusinessException(__('messages.cash_station_settlement_self_transfer_not_allowed'));
        }

        $transferable = $this->buildCashStationAction->transferableBalance($fromProjectId, $month, $year);
        $requested = round((float) $amount, 2);

        if ($transferable <= 0) {
            throw new BusinessException(__('messages.cash_station_settlement_requires_surplus'));
        }

        if ($requested > $transferable) {
            throw new BusinessException(__('messages.cash_station_settlement_exceeds_transferable_balance'));
        }
    }
}
