<?php

namespace Modules\AdministrativeDebtSettlement\Actions;

use Modules\AdministrativeDebtSettlement\Enums\AdministrativeDebtSettlementStatus;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;

class CreateAdministrativeDebtSettlementAction
{
    /**
     * @param  array{
     *     administrative_debt: float,
     *     current_remaining: float,
     *     carried_remaining: float,
     *     cash_box_capacity: float,
     *     surplus: float
     * }  $snapshot
     */
    public function execute(
        int $year,
        int $month,
        int $projectId,
        float $amount,
        array $snapshot,
        ?string $settledBy,
    ): AdministrativeDebtSettlement {
        // Mandatory priority: Cash Box Deficit → Current Admin Debt → Carried Admin Debt.
        // Cash-box allocation consumes surplus capacity only (no Net Cash mutation).
        $remaining = round($amount, 2);

        $allocatedCashBox = round(min($remaining, (float) $snapshot['cash_box_capacity']), 2);
        $remaining = round($remaining - $allocatedCashBox, 2);

        $allocatedCurrent = round(min($remaining, (float) $snapshot['current_remaining']), 2);
        $remaining = round($remaining - $allocatedCurrent, 2);

        $allocatedCarried = round(min($remaining, (float) $snapshot['carried_remaining']), 2);
        $remaining = round($remaining - $allocatedCarried, 2);

        // Leftover after debt buckets (e.g. cash-box-only settle) stays unallocated to debt.
        if ($remaining > 0 && $allocatedCashBox <= 0) {
            $allocatedCarried = round($allocatedCarried + $remaining, 2);
            $remaining = 0.0;
        }

        $debtApplied = round($allocatedCurrent + $allocatedCarried, 2);
        $debtAfter = round(max(0, (float) $snapshot['administrative_debt'] - $debtApplied), 2);
        $status = $debtAfter <= 0
            ? AdministrativeDebtSettlementStatus::Paid
            : AdministrativeDebtSettlementStatus::Partial;

        return AdministrativeDebtSettlement::query()->create([
            'year' => $year,
            'month' => $month,
            'project_id' => $projectId,
            'surplus_at_settlement' => $snapshot['surplus'],
            'recoverable_amount' => $amount,
            'allocated_cash_box' => $allocatedCashBox,
            'allocated_current_debt' => $allocatedCurrent,
            'allocated_carried_debt' => $allocatedCarried,
            'status' => $status,
            'settled_by' => $settledBy,
        ]);
    }
}
