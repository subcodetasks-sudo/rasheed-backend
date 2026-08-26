<?php

namespace Modules\CashStation\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\CashStation\Models\FinancialMonthLock;
use Modules\CashStation\Models\FinancialMonthProjectSnapshot;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;

class FreezeFinancialMonthAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ) {}

    /**
     * Freeze a closed calendar month into snapshots. Idempotent when already locked.
     *
     * @return FinancialMonthLock|null Null when the month is the current calendar month (never freeze).
     */
    public function execute(int $year, int $month, ?string $frozenBy = null): ?FinancialMonthLock
    {
        $today = Carbon::today()->startOfDay();
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();

        if ($monthStart->greaterThanOrEqualTo($today->copy()->startOfMonth())) {
            return null;
        }

        $existing = FinancialMonthLock::query()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($year, $month, $frozenBy) {
            $locked = FinancialMonthLock::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($locked !== null) {
                return $locked;
            }

            $cashStation = $this->buildCashStationAction->buildLive($month, $year);
            $monthlySummary = $this->buildMonthlySummaryAction->buildLive($month, $year);

            $msByProject = [];
            foreach ($monthlySummary['projects'] as $row) {
                $msByProject[(int) $row['project_id']] = $row;
            }

            $csByProject = [];
            foreach ($cashStation['projects'] as $row) {
                $csByProject[(int) $row['project_id']] = $row;
            }

            $projectIds = array_unique(array_merge(array_keys($csByProject), array_keys($msByProject)));

            $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
            $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
            $aggregates = $this->buildCashStationAction->monthlyAggregatesByProject(
                $projectIds,
                $startOfMonth->toDateString(),
                $endOfMonth->toDateString(),
            );

            foreach ($projectIds as $projectId) {
                $cs = $csByProject[$projectId] ?? null;
                $ms = $msByProject[$projectId] ?? null;
                $aggregate = $aggregates[$projectId] ?? null;

                FinancialMonthProjectSnapshot::query()->create([
                    'year' => $year,
                    'month' => $month,
                    'project_id' => $projectId,
                    'project_name' => (string) ($ms['project_name'] ?? $cs['project_name'] ?? ''),
                    'project_type' => $ms['project_type'] ?? null,
                    'project_status' => $ms['project_status'] ?? null,
                    'previous_monthly_total' => (float) ($cs['previous_monthly_total'] ?? 0),
                    'monthly_revenue' => (float) ($aggregate->monthly_revenue ?? 0),
                    'monthly_expenses' => (float) ($aggregate->monthly_expenses ?? 0),
                    'administrative_percentage' => (float) ($aggregate->administrative_percentage ?? 0),
                    'operational_deduction' => (float) ($aggregate->operational_deduction ?? 0),
                    'monthly_total' => (float) ($cs['monthly_total'] ?? 0),
                    'cs_administrative_debt' => (float) ($cs['administrative_debt'] ?? 0),
                    'remaining_administrative_debt' => (float) ($cs['remaining_administrative_debt'] ?? 0),
                    'cs_added_contribution' => (float) ($cs['added_contribution'] ?? 0),
                    'cs_deducted_contribution' => (float) ($cs['deducted_contribution'] ?? 0),
                    'net_cash_fund' => (float) ($cs['net_cash_fund'] ?? 0),
                    'cash_status' => (string) ($cs['status'] ?? 'balanced'),
                    'fund_balance_tip' => (float) ($ms['project_net_result'] ?? 0),
                    'ms_administrative_debt' => (float) ($ms['administrative_debt'] ?? 0),
                    'ms_added_contribution' => (float) ($ms['total_received_contributions'] ?? 0),
                    'ms_deducted_contribution' => (float) ($ms['total_deducted_contributions'] ?? 0),
                ]);
            }

            return FinancialMonthLock::query()->create([
                'year' => $year,
                'month' => $month,
                'frozen_at' => now(),
                'frozen_by' => $frozenBy,
                'carried_forward_from_previous' => (bool) ($cashStation['carried_forward_from_previous'] ?? false),
                'calculation_date' => $monthlySummary['calculation_date'] ?? $endOfMonth->toDateString(),
            ]);
        });
    }

    /**
     * Freeze every calendar month strictly before the current month that has financial activity
     * and is not yet locked.
     *
     * @return list<FinancialMonthLock>
     */
    public function freezeAllClosed(?string $frozenBy = null): array
    {
        $earliest = $this->earliestActivityMonth();
        if ($earliest === null) {
            return [];
        }

        $cursor = $earliest->copy()->startOfMonth();
        $currentMonthStart = Carbon::today()->startOfMonth();
        $frozen = [];

        while ($cursor->lt($currentMonthStart)) {
            $lock = $this->execute((int) $cursor->year, (int) $cursor->month, $frozenBy);
            if ($lock !== null) {
                $frozen[] = $lock;
            }
            $cursor->addMonthNoOverflow();
        }

        return $frozen;
    }

    private function earliestActivityMonth(): ?Carbon
    {
        $candidates = [];

        $journalMin = DailyJournalEntry::query()->min('journal_date');
        if ($journalMin !== null) {
            $candidates[] = Carbon::parse($journalMin)->startOfMonth();
        }

        $settlement = CashStationSettlement::query()
            ->orderBy('year')
            ->orderBy('month')
            ->first(['year', 'month']);
        if ($settlement !== null) {
            $candidates[] = Carbon::create((int) $settlement->year, (int) $settlement->month, 1)->startOfMonth();
        }

        $carry = CashStationMonthCarry::query()
            ->orderBy('from_year')
            ->orderBy('from_month')
            ->first(['from_year', 'from_month']);
        if ($carry !== null) {
            $candidates[] = Carbon::create((int) $carry->from_year, (int) $carry->from_month, 1)->startOfMonth();
        }

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sortBy(fn (Carbon $c) => $c->timestamp)->first();
    }
}
