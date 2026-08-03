<?php

namespace Modules\AdministrativeDebtSettlement\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\AdministrativeDebtSettlement\Actions\ApplyAdministrativeDebtSettlementToJournalAction;
use Modules\AdministrativeDebtSettlement\Actions\BuildAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Actions\CreateAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Actions\ValidateAdministrativeDebtSettlementAction;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\DailyJournal\Services\AdministrativePercentageBalanceService;

class StoreAdministrativeDebtSettlementWorkflow
{
    public function __construct(
        private readonly ValidateAdministrativeDebtSettlementAction $validateAdministrativeDebtSettlementAction,
        private readonly CreateAdministrativeDebtSettlementAction $createAdministrativeDebtSettlementAction,
        private readonly ApplyAdministrativeDebtSettlementToJournalAction $applyAdministrativeDebtSettlementToJournalAction,
        private readonly AdministrativePercentageBalanceService $administrativePercentageBalanceService,
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildAdministrativeDebtSettlementAction $buildAdministrativeDebtSettlementAction,
    ) {}

    public function handle(int $year, int $month, int $projectId, ?float $amount): AdministrativeDebtSettlement
    {
        $settlement = DB::transaction(function () use ($year, $month, $projectId, $amount) {
            $validated = $this->validateAdministrativeDebtSettlementAction->execute(
                $year,
                $month,
                $projectId,
                $amount,
            );

            $settlement = $this->createAdministrativeDebtSettlementAction->execute(
                $year,
                $month,
                $projectId,
                $validated['amount'],
                $validated['snapshot'],
                auth()->user()?->uuid,
            );

            $this->applyAdministrativeDebtSettlementToJournalAction->execute($settlement);

            $debtCredit = round(
                (float) $settlement->allocated_current_debt + (float) $settlement->allocated_carried_debt,
                2,
            );

            if ($debtCredit > 0) {
                $this->administrativePercentageBalanceService->creditFromDebtSettlement(
                    $settlement,
                    $debtCredit,
                );
            }

            return $settlement;
        });

        AdministrativeDebtSettlementCreated::dispatch($settlement);
        AdministrativeDebtSettlementUpdated::dispatch(
            $settlement->year,
            $settlement->month,
            $this->buildAdministrativeDebtSettlementAction->execute($settlement->month, $settlement->year),
        );
        CashStationUpdated::dispatch(
            $settlement->year,
            $settlement->month,
            $this->buildCashStationAction->execute($settlement->month, $settlement->year),
        );

        return $settlement;
    }
}
