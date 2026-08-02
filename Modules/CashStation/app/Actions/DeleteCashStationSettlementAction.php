<?php

namespace Modules\CashStation\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\Project\Exceptions\BusinessException;

class DeleteCashStationSettlementAction
{
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

        $settlement->delete();

        return [
            'year' => $year,
            'month' => $month,
        ];
    }
}
