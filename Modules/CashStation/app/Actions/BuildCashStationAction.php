<?php

namespace Modules\CashStation\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Actions\ReadAccumulatedAdministrativeDebtTipAction;
use Modules\Project\Models\Project;

class BuildCashStationAction
{
    /**
     * @var array<string, array{
     *     carriedFromPrevious: bool,
     *     currentAggregates: array<int, object>,
     *     previousAggregates: array<int, object>,
     *     contributions: array<int, array{added: float, deducted: float}>
     * }>
     */
    private array $monthBalanceContextCache = [];

    public function execute(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();

        $previousMonth = $startOfMonth->copy()->subMonthNoOverflow();
        $previousYear = (int) $previousMonth->year;
        $previousMonthNumber = (int) $previousMonth->month;

        $carriedFromPrevious = $this->hasCarryFromPrevious($previousYear, $previousMonthNumber, $year, $month);

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->active()
            ->orderBy('id')
            ->get(['id', 'name']);

        $projectIds = $projects->pluck('id')->all();

        $currentAggregates = $this->monthlyAggregatesByProject(
            $projectIds,
            $startOfMonth->toDateString(),
            $endOfMonth->toDateString(),
        );

        $previousAggregates = $carriedFromPrevious
            ? $this->monthlyAggregatesByProject(
                $projectIds,
                $previousMonth->copy()->startOfMonth()->toDateString(),
                $previousMonth->copy()->endOfMonth()->toDateString(),
            )
            : [];

        $debts = $this->administrativeDebtsByProject($projectIds, $endOfMonth->toDateString());
        $adsDebtSettledThisMonth = $this->administrativeDebtSettledInMonthByProject($projectIds, $year, $month);
        $settlements = $this->settlementsForMonth($year, $month);
        $contributions = $this->contributionsByProject($settlements);

        $projectRows = [];
        $totalSurplus = 0.0;
        $totalDeficit = 0.0;
        $totalAdminDebts = 0.0;
        $totalNetCashFunds = 0.0;
        $totalRevenue = 0.0;
        $totalExpenses = 0.0;
        $totalAdminPercentage = 0.0;
        $totalOperationalDeduction = 0.0;
        $totalNetMonth = 0.0;

        foreach ($projects as $project) {
            $aggregate = $currentAggregates[$project->id] ?? null;

            $monthlyRevenue = (float) ($aggregate->monthly_revenue ?? 0);
            $monthlyExpenses = (float) ($aggregate->monthly_expenses ?? 0);
            $administrativePercentage = (float) ($aggregate->administrative_percentage ?? 0);
            $operationalDeduction = (float) ($aggregate->operational_deduction ?? 0);
            $monthlyTotal = $this->monthlyTotalFromAggregate($aggregate);

            $previousMonthlyTotal = 0.0;
            if ($carriedFromPrevious) {
                $previousMonthlyTotal = $this->monthlyTotalFromAggregate($previousAggregates[$project->id] ?? null);
            }

            $added = (float) ($contributions[$project->id]['added'] ?? 0);
            $deducted = (float) ($contributions[$project->id]['deducted'] ?? 0);
            $netCashFund = $previousMonthlyTotal + $monthlyTotal + $added - $deducted;

            $originatingDebt = (float) ($debts[$project->id] ?? 0);
            $settledDebtThisMonth = (float) ($adsDebtSettledThisMonth[$project->id] ?? 0);
            // Journal tip is reduced on ADS settle; remaining is the journal balance.
            // Originating display reconstructs pre-settlement month debt for the card.
            $administrativeDebt = max(0.0, round($originatingDebt, 2));
            $originatingDisplay = max(0.0, round($administrativeDebt + $settledDebtThisMonth, 2));

            $projectRows[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'previous_monthly_total' => FormatMoneyDecimal::format($previousMonthlyTotal),
                'monthly_total' => FormatMoneyDecimal::format($monthlyTotal),
                'administrative_debt' => FormatMoneyDecimal::format($originatingDisplay),
                'added_contribution' => FormatMoneyDecimal::format($added),
                'deducted_contribution' => FormatMoneyDecimal::format($deducted),
                'net_cash_fund' => FormatMoneyDecimal::format($netCashFund),
                'remaining_administrative_debt' => FormatMoneyDecimal::format($administrativeDebt),
                'status' => $this->cashBoxStatus($netCashFund),
            ];

            // Surplus/deficit cards use Monthly Total only (before settlements / carry-forward).
            if ($monthlyTotal > 0) {
                $totalSurplus += $monthlyTotal;
            } elseif ($monthlyTotal < 0) {
                $totalDeficit += abs($monthlyTotal);
            }

            $totalAdminDebts += $administrativeDebt;
            $totalNetCashFunds += $netCashFund;
            $totalRevenue += $monthlyRevenue;
            $totalExpenses += $monthlyExpenses;
            $totalAdminPercentage += $administrativePercentage;
            $totalOperationalDeduction += $operationalDeduction;
            $totalNetMonth += $monthlyTotal;
        }

        return [
            'month' => ['month' => $month, 'year' => $year],
            'carried_forward_from_previous' => $carriedFromPrevious,
            'summary' => [
                'total_monthly_surplus' => FormatMoneyDecimal::format($totalSurplus),
                'total_monthly_deficit' => FormatMoneyDecimal::format($totalDeficit),
                'administrative_debts' => FormatMoneyDecimal::format($totalAdminDebts),
                'net_cash_funds' => FormatMoneyDecimal::format($totalNetCashFunds),
                'monthly_revenue' => FormatMoneyDecimal::format($totalRevenue),
                'monthly_expenses' => FormatMoneyDecimal::format($totalExpenses),
                'total_administrative_percentage' => FormatMoneyDecimal::format($totalAdminPercentage),
                'total_operational_deduction' => FormatMoneyDecimal::format($totalOperationalDeduction),
                'net_month' => FormatMoneyDecimal::format($totalNetMonth),
            ],
            'projects' => $projectRows,
            'settlements' => $settlements->map(fn (CashStationSettlement $settlement) => [
                'id' => $settlement->id,
                'year' => $settlement->year,
                'month' => $settlement->month,
                'from_project_id' => $settlement->from_project_id,
                'to_project_id' => $settlement->to_project_id,
                'amount' => FormatMoneyDecimal::format($settlement->amount),
                'contribution_type' => $settlement->contribution_type?->value,
            ])->values()->all(),
        ];
    }

    /**
     * Transferable surplus for a project in a month = max(0, previous + monthly_total + added − deducted).
     */
    public function transferableBalance(int $projectId, int $month, int $year): float
    {
        return max(0.0, $this->netCashFundForProject($projectId, $month, $year));
    }

    public function hasCarryFromPreviousMonth(int $month, int $year): bool
    {
        $previousMonth = Carbon::create($year, $month, 1)->startOfDay()->subMonthNoOverflow();

        return $this->hasCarryFromPrevious(
            (int) $previousMonth->year,
            (int) $previousMonth->month,
            $year,
            $month,
        );
    }

    private function hasCarryFromPrevious(int $fromYear, int $fromMonth, int $toYear, int $toMonth): bool
    {
        return CashStationMonthCarry::query()
            ->where('from_year', $fromYear)
            ->where('from_month', $fromMonth)
            ->where('to_year', $toYear)
            ->where('to_month', $toMonth)
            ->exists();
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, object{
     *     project_id: int,
     *     monthly_revenue: float|string,
     *     monthly_expenses: float|string,
     *     administrative_percentage: float|string,
     *     operational_deduction: float|string
     * }>
     */
    public function monthlyAggregatesByProject(array $projectIds, string $startDate, string $endDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        // administrative_percentage here is collected intake only (fee − debt + contribution).
        // Unpaid fee stays in Administrative Debt and must not reduce Monthly Total / Net Monthly Result.
        // Exempt projects contribute 0.
        $rows = DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->whereIn('daily_journal_entries.project_id', $projectIds)
            ->where('daily_journal_entries.journal_date', '>=', $startDate)
            ->where('daily_journal_entries.journal_date', '<=', $endDate)
            ->groupBy('daily_journal_entries.project_id')
            ->selectRaw('daily_journal_entries.project_id as project_id')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_income, 0)), 0) as monthly_revenue')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.daily_expense, 0)), 0) as monthly_expenses')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN projects.administrative_exempt = 0 THEN '
                .'COALESCE(daily_journal_entries.administrative_fee, 0)'
                .' - COALESCE(daily_journal_entries.administrative_debt, 0)'
                .' + COALESCE(daily_journal_entries.contribution, 0)'
                .' ELSE 0 END), 0) as administrative_percentage'
            )
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.operational_deduction, 0)), 0) as operational_deduction')
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->project_id] = $row;
        }

        return $keyed;
    }

    public function monthlyTotalFromAggregate(mixed $aggregate): float
    {
        if ($aggregate === null) {
            return 0.0;
        }

        return (float) ($aggregate->monthly_revenue ?? 0)
            - (float) ($aggregate->administrative_percentage ?? 0)
            - (float) ($aggregate->operational_deduction ?? 0)
            - (float) ($aggregate->monthly_expenses ?? 0);
    }

    /**
     * Debt allocated by ADS settlements in the selected month only (not prior months).
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    private function administrativeDebtSettledInMonthByProject(array $projectIds, int $year, int $month): array
    {
        if ($projectIds === []) {
            return [];
        }

        if (! Schema::hasTable('administrative_debt_settlements')) {
            return [];
        }

        $rows = DB::table('administrative_debt_settlements')
            ->whereIn('project_id', $projectIds)
            ->where('year', $year)
            ->where('month', $month)
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

    private function cashBoxStatus(float $netCashFund): string
    {
        if ($netCashFund > 0) {
            return 'surplus';
        }

        if ($netCashFund < 0) {
            return 'deficit';
        }

        return 'balanced';
    }

    /**
     * Net cash fund for a project in a month (can be negative).
     */
    public function netCashFundForProject(int $projectId, int $month, int $year): float
    {
        $context = $this->monthBalanceContext($month, $year);

        $previousMonthlyTotal = 0.0;
        if ($context['carriedFromPrevious']) {
            $previousMonthlyTotal = $this->monthlyTotalFromAggregate(
                $this->aggregateForProjectInContext($context, $projectId, $month, $year, previous: true)
            );
        }

        $monthlyTotal = $this->monthlyTotalFromAggregate(
            $this->aggregateForProjectInContext($context, $projectId, $month, $year, previous: false)
        );
        $added = (float) ($context['contributions'][$projectId]['added'] ?? 0);
        $deducted = (float) ($context['contributions'][$projectId]['deducted'] ?? 0);

        return $previousMonthlyTotal + $monthlyTotal + $added - $deducted;
    }

    /**
     * @return array{
     *     carriedFromPrevious: bool,
     *     currentAggregates: array<int, object>,
     *     previousAggregates: array<int, object>,
     *     contributions: array<int, array{added: float, deducted: float}>
     * }
     */
    private function monthBalanceContext(int $month, int $year): array
    {
        $key = "{$year}-{$month}";
        if (isset($this->monthBalanceContextCache[$key])) {
            return $this->monthBalanceContextCache[$key];
        }

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $previousMonth = $startOfMonth->copy()->subMonthNoOverflow();

        $carriedFromPrevious = $this->hasCarryFromPrevious(
            (int) $previousMonth->year,
            (int) $previousMonth->month,
            $year,
            $month,
        );

        $projectIds = Project::query()->active()->pluck('id')->all();

        $currentAggregates = $this->monthlyAggregatesByProject(
            $projectIds,
            $startOfMonth->toDateString(),
            $endOfMonth->toDateString(),
        );

        $previousAggregates = [];
        if ($carriedFromPrevious) {
            $previousAggregates = $this->monthlyAggregatesByProject(
                $projectIds,
                $previousMonth->copy()->startOfMonth()->toDateString(),
                $previousMonth->copy()->endOfMonth()->toDateString(),
            );
        }

        $contributions = $this->contributionsByProject(
            $this->settlementsForMonth($year, $month)
        );

        return $this->monthBalanceContextCache[$key] = [
            'carriedFromPrevious' => $carriedFromPrevious,
            'currentAggregates' => $currentAggregates,
            'previousAggregates' => $previousAggregates,
            'contributions' => $contributions,
        ];
    }

    /**
     * @param  array{
     *     carriedFromPrevious: bool,
     *     currentAggregates: array<int, object>,
     *     previousAggregates: array<int, object>,
     *     contributions: array<int, array{added: float, deducted: float}>
     * }  $context
     */
    private function aggregateForProjectInContext(
        array $context,
        int $projectId,
        int $month,
        int $year,
        bool $previous,
    ): mixed {
        $aggregates = $previous ? $context['previousAggregates'] : $context['currentAggregates'];

        if (array_key_exists($projectId, $aggregates)) {
            return $aggregates[$projectId];
        }

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        if ($previous) {
            $monthAnchor = $startOfMonth->copy()->subMonthNoOverflow();
            $start = $monthAnchor->copy()->startOfMonth()->toDateString();
            $end = $monthAnchor->copy()->endOfMonth()->toDateString();
        } else {
            $start = $startOfMonth->toDateString();
            $end = $startOfMonth->copy()->endOfMonth()->toDateString();
        }

        $loaded = $this->monthlyAggregatesByProject([$projectId], $start, $end);

        return $loaded[$projectId] ?? null;
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, float>
     */
    public function administrativeDebtsByProject(array $projectIds, string $asOfDate): array
    {
        return (new ReadAccumulatedAdministrativeDebtTipAction)->execute($projectIds, $asOfDate);
    }

    /**
     * @return Collection<int, CashStationSettlement>
     */
    public function settlementsForMonth(int $year, int $month): Collection
    {
        return CashStationSettlement::query()
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, CashStationSettlement>  $settlements
     * @return array<int, array{added: float, deducted: float}>
     */
    public function contributionsByProject(Collection $settlements): array
    {
        $contributions = [];

        foreach ($settlements as $settlement) {
            $amount = (float) $settlement->amount;

            if (! isset($contributions[$settlement->to_project_id])) {
                $contributions[$settlement->to_project_id] = ['added' => 0.0, 'deducted' => 0.0];
            }
            if (! isset($contributions[$settlement->from_project_id])) {
                $contributions[$settlement->from_project_id] = ['added' => 0.0, 'deducted' => 0.0];
            }

            $contributions[$settlement->to_project_id]['added'] += $amount;
            $contributions[$settlement->from_project_id]['deducted'] += $amount;
        }

        return $contributions;
    }
}
