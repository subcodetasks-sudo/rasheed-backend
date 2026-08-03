<?php

namespace Modules\CashStation\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Actions\CreateCashStationSettlementAction;
use Modules\CashStation\Actions\ValidateCashStationSettlementAction;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationSettlement;

class StoreCashStationSettlementWorkflow
{
    public function __construct(
        private readonly ValidateCashStationSettlementAction $validateCashStationSettlementAction,
        private readonly CreateCashStationSettlementAction $createCashStationSettlementAction,
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function handle(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        string $amount,
    ): CashStationSettlement {
        $settlement = DB::transaction(function () use ($year, $month, $fromProjectId, $toProjectId, $amount) {
            $this->validateCashStationSettlementAction->execute(
                $year,
                $month,
                $fromProjectId,
                $toProjectId,
                $amount,
            );

            return $this->createCashStationSettlementAction->execute(
                $year,
                $month,
                $fromProjectId,
                $toProjectId,
                $amount,
                auth()->user()?->uuid,
            );
        });

        CashStationSettlementCreated::dispatch($settlement);
        CashStationUpdated::dispatch(
            $settlement->year,
            $settlement->month,
            $this->buildCashStationAction->execute($settlement->month, $settlement->year),
        );

        return $settlement;
    }
}
