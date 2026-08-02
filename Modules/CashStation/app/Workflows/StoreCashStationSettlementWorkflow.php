<?php

namespace Modules\CashStation\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\CreateCashStationSettlementAction;
use Modules\CashStation\Actions\ValidateCashStationSettlementAction;
use Modules\CashStation\Models\CashStationSettlement;

class StoreCashStationSettlementWorkflow
{
    public function __construct(
        private readonly ValidateCashStationSettlementAction $validateCashStationSettlementAction,
        private readonly CreateCashStationSettlementAction $createCashStationSettlementAction,
    ) {}

    public function handle(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        string $amount,
    ): CashStationSettlement {
        return DB::transaction(function () use ($year, $month, $fromProjectId, $toProjectId, $amount) {
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
    }
}
