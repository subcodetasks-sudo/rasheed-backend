<?php

namespace Modules\AdministrativeDebtSettlement\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AdministrativeDebtSettlement\Enums\AdministrativeDebtSettlementStatus;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\CashStation\Actions\BuildCashStationAction;

class BuildAdministrativeDebtSettlementAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        $cashStation = $this->buildCashStationAction->execute($month, $year);
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $previousMonth = $startOfMonth->copy()->subMonthNoOverflow();

        $projectIds = collect($cashStation['projects'])->pluck('project_id')->map(fn ($id) => (int) $id)->all();
        $settlementsByProject = $this->settlementsGroupedByProject($projectIds);
        $currentMonthDebtSums = $this->currentMonthAdministrativeDebtSums(
            $projectIds,
            $startOfMonth->toDateString(),
            $endOfMonth->toDateString(),
        );
        $priorAccumulated = $this->accumulatedAsOf(
            $projectIds,
            $previousMonth->copy()->endOfMonth()->toDateString(),
        );
        $monthEndAccumulated = $this->accumulatedAsOf($projectIds, $endOfMonth->toDateString());

        $rows = [];

        foreach ($cashStation['projects'] as $project) {
            $projectId = (int) $project['project_id'];
            $snapshot = $this->projectDebtSnapshot(
                $projectId,
                $year,
                $month,
                (float) $project['net_cash_fund'],
                (float) $project['previous_monthly_total'],
                (float) $project['monthly_total'],
                (float) $project['added_contribution'],
                (float) $project['deducted_contribution'],
                $settlementsByProject[$projectId] ?? collect(),
                (float) ($currentMonthDebtSums[$projectId] ?? 0),
                (float) ($priorAccumulated[$projectId] ?? 0),
                (float) ($monthEndAccumulated[$projectId] ?? 0),
            );

            if ($snapshot['administrative_debt'] <= 0) {
                continue;
            }

            $rows[] = [
                'project_id' => $projectId,
                'project_name' => $project['project_name'],
                'net_cash_balance' => $this->decimal($snapshot['net_cash_balance']),
                'administrative_debt' => $this->decimal($snapshot['administrative_debt']),
                'recoverable_amount' => $this->decimal($snapshot['recoverable_amount']),
                'remaining_debt' => $this->decimal($snapshot['remaining_debt']),
                'settlement_status' => $snapshot['settlement_status']->value,
                'can_settle' => $snapshot['can_settle'],
            ];
        }

        return [
            // 'month' => ['month' => $month, 'year' => $year],
            'projects' => $rows,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AdministrativeDebtSettlement>  $projectSettlements
     * @return array{
     *     net_cash_balance: float,
     *     administrative_debt: float,
     *     current_remaining: float,
     *     carried_remaining: float,
     *     recoverable_amount: float,
     *     remaining_debt: float,
     *     settlement_status: AdministrativeDebtSettlementStatus,
     *     can_settle: bool,
     *     cash_box_capacity: float,
     *     surplus: float
     * }
     */
    public function projectDebtSnapshot(
        int $projectId,
        int $year,
        int $month,
        float $netCashFund,
        float $previousMonthlyTotal,
        float $monthlyTotal,
        float $addedContribution,
        float $deductedContribution,
        $projectSettlements,
        float $currentMonthDebtOrig,
        float $priorAccumulated,
        float $monthEndAccumulated,
    ): array {
        $thisMonthSettlements = $projectSettlements
            ->filter(fn (AdministrativeDebtSettlement $s) => $s->year === $year && $s->month === $month);

        $settledCurrentThisMonth = round((float) $thisMonthSettlements->sum(
            fn (AdministrativeDebtSettlement $s) => (float) $s->allocated_current_debt
        ), 2);
        $settledCarriedThisMonth = round((float) $thisMonthSettlements->sum(
            fn (AdministrativeDebtSettlement $s) => (float) $s->allocated_carried_debt
        ), 2);
        $settledCashBoxThisMonth = round((float) $thisMonthSettlements->sum(
            fn (AdministrativeDebtSettlement $s) => (float) $s->allocated_cash_box
        ), 2);
        $settledSurplusThisMonth = round((float) $thisMonthSettlements->sum(
            fn (AdministrativeDebtSettlement $s) => (float) $s->recoverable_amount
        ), 2);

        $totalDebtSettledThroughMonth = round((float) $projectSettlements
            ->filter(fn (AdministrativeDebtSettlement $s) => $this->periodLessOrEqual($s->year, $s->month, $year, $month))
            ->sum(fn (AdministrativeDebtSettlement $s) => $this->debtAllocated($s)), 2);

        // Prior-month journal tip is already reduced when those months were settled, so do not
        // subtract priorDebtSettled again. This-month carried allocations still reduce the bucket.
        $carriedRemaining = round(max(0, $priorAccumulated - $settledCarriedThisMonth), 2);
        $currentRemaining = round(max(0, $currentMonthDebtOrig - $settledCurrentThisMonth), 2);
        $administrativeDebt = round(max(0, $monthEndAccumulated), 2);

        if ($administrativeDebt > 0 && abs(($currentRemaining + $carriedRemaining) - $administrativeDebt) > 0.01) {
            $splitTotal = $currentRemaining + $carriedRemaining;
            if ($splitTotal > 0) {
                $currentRemaining = round($administrativeDebt * ($currentRemaining / $splitTotal), 2);
                $carriedRemaining = round(max(0, $administrativeDebt - $currentRemaining), 2);
            } else {
                $carriedRemaining = $administrativeDebt;
                $currentRemaining = 0.0;
            }
        }

        $grossSurplus = round(max(0, $netCashFund), 2);
        // Cumulative surplus capacity: prior ADS settlements this month consume surplus without mutating Net Cash.
        $surplus = round(max(0, $grossSurplus - $settledSurplusThisMonth), 2);

        $openingDeficit = round(max(0, -$previousMonthlyTotal), 2);
        $currentActivity = round($monthlyTotal + $addedContribution - $deductedContribution, 2);
        $rawCashBoxCapacity = round(max(0, $openingDeficit - max(0, $currentActivity)), 2);
        $cashBoxCapacity = round(max(0, $rawCashBoxCapacity - $settledCashBoxThisMonth), 2);

        // Surplus reserved for cash-box first; remainder is available for admin debt recovery.
        $debtSurplus = round(max(0, $surplus - $cashBoxCapacity), 2);
        $recoverableAmount = round(min($administrativeDebt, $debtSurplus), 2);

        $hasSettlements = $totalDebtSettledThroughMonth > 0 || $settledSurplusThisMonth > 0;

        $status = match (true) {
            $administrativeDebt <= 0 => AdministrativeDebtSettlementStatus::Paid,
            $hasSettlements => AdministrativeDebtSettlementStatus::Partial,
            default => AdministrativeDebtSettlementStatus::Unpaid,
        };

        return [
            'net_cash_balance' => round($netCashFund, 2),
            'administrative_debt' => $administrativeDebt,
            'current_remaining' => $currentRemaining,
            'carried_remaining' => $carriedRemaining,
            'recoverable_amount' => $recoverableAmount,
            'remaining_debt' => $administrativeDebt,
            'settlement_status' => $status,
            'can_settle' => $surplus > 0 && $administrativeDebt > 0 && $recoverableAmount > 0,
            'cash_box_capacity' => $cashBoxCapacity,
            'surplus' => $surplus,
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, \Illuminate\Support\Collection<int, AdministrativeDebtSettlement>>
     */
    public function settlementsGroupedByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return AdministrativeDebtSettlement::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('year')
            ->orderBy('month')
            ->orderBy('id')
            ->get()
            ->groupBy('project_id')
            ->all();
    }

    /**
     * Debt settled through month-end for Cash Station remaining-debt indicators.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function debtSettledThroughMonthByProject(array $projectIds, int $year, int $month): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = DB::table('administrative_debt_settlements')
            ->whereIn('project_id', $projectIds)
            ->where(function ($query) use ($year, $month) {
                $query->where('year', '<', $year)
                    ->orWhere(function ($q) use ($year, $month) {
                        $q->where('year', $year)->where('month', '<=', $month);
                    });
            })
            ->groupBy('project_id')
            ->selectRaw('project_id')
            ->selectRaw('COALESCE(SUM(COALESCE(allocated_current_debt, 0) + COALESCE(allocated_carried_debt, 0)), 0) as total')
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->project_id] = (float) $row->total;
        }

        return $keyed;
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function currentMonthAdministrativeDebtSums(array $projectIds, string $startDate, string $endDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = DB::table('daily_journal_entries')
            ->whereIn('project_id', $projectIds)
            ->where('journal_date', '>=', $startDate)
            ->where('journal_date', '<=', $endDate)
            ->groupBy('project_id')
            ->selectRaw('project_id')
            ->selectRaw('COALESCE(SUM(COALESCE(administrative_debt, 0)), 0) as total')
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->project_id] = (float) $row->total;
        }

        return $keyed;
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function accumulatedAsOf(array $projectIds, string $asOfDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $debts = [];

        foreach ($projectIds as $projectId) {
            $row = DB::table('daily_journal_entries')
                ->where('project_id', $projectId)
                ->where('journal_date', '<=', $asOfDate)
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->first(['accumulated_administrative_debt']);

            if ($row !== null) {
                $debts[$projectId] = (float) ($row->accumulated_administrative_debt ?? 0);
            }
        }

        return $debts;
    }

    private function debtAllocated(AdministrativeDebtSettlement $settlement): float
    {
        return round((float) $settlement->allocated_current_debt + (float) $settlement->allocated_carried_debt, 2);
    }

    private function periodLessOrEqual(int $year, int $month, int $refYear, int $refMonth): bool
    {
        return ($year * 12 + $month) <= ($refYear * 12 + $refMonth);
    }

    private function decimal(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
