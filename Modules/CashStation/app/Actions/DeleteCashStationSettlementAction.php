<?php

namespace Modules\CashStation\Actions;

use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\Project\Exceptions\BusinessException;

class DeleteCashStationSettlementAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
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

        $settlement->delete();

        CashStationSettlementDeleted::dispatch($settlementId, $year, $month);
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
