<?php

namespace Modules\AdministrativeFund\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AdministrativeFund\Models\AdministrativeFundDay;
use Modules\OperationalFund\Actions\LoadOperationalExpensesByDateAction;

class BuildAdministrativeFundAction
{
    public function __construct(
        private readonly LoadOperationalExpensesByDateAction $loadOperationalExpensesByDateAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $start = $startOfMonth->toDateString();
        $end = $endOfMonth->toDateString();

        $projectAdminByDate = $this->projectAdministrationByDate($start, $end);
        $debtRecoveryByDate = $this->debtRecoveryByDate($year, $month);
        $manualByDate = $this->manualByDate($start, $end);
        $operationalExpensesByDate = $this->loadOperationalExpensesByDateAction->execute($start, $end);

        $days = [];
        $sums = $this->emptyMoneyBag();

        $cursor = $startOfMonth->copy();
        while ($cursor->lte($endOfMonth)) {
            $dateString = $cursor->toDateString();
            $manual = $manualByDate[$dateString] ?? null;

            $projectAdministration = round((float) ($projectAdminByDate[$dateString] ?? 0), 2);
            $cashFundContributions = 0.0;
            $individualContributions = round((float) ($manual['individual_contributions'] ?? 0), 2);
            $debtRecovery = round((float) ($debtRecoveryByDate[$dateString] ?? 0), 2);
            $operationalAdministration = round((float) ($operationalExpensesByDate[$dateString] ?? 0), 2);
            $assetAdministration = round((float) ($manual['asset_administration'] ?? 0), 2);

            $totalIncome = round(
                $projectAdministration + $cashFundContributions + $individualContributions + $debtRecovery,
                2,
            );
            $totalExpenses = round($operationalAdministration + $assetAdministration, 2);
            $net = round($totalIncome - $totalExpenses, 2);

            $row = [
                'date' => $dateString,
                'day' => $cursor->format('l'),
                'project_administration' => $this->decimal($projectAdministration),
                'cash_fund_contributions' => $this->decimal($cashFundContributions),
                'individual_contributions' => $this->decimal($individualContributions),
                'debt_recovery' => $this->decimal($debtRecovery),
                'total_income' => $this->decimal($totalIncome),
                'operational_administration' => $this->decimal($operationalAdministration),
                'asset_administration' => $this->decimal($assetAdministration),
                'total_expenses' => $this->decimal($totalExpenses),
                'net' => $this->decimal($net),
                'notes' => $manual['notes'] ?? null,
            ];

            $days[] = $row;

            $sums['project_administration'] += $projectAdministration;
            $sums['cash_fund_contributions'] += $cashFundContributions;
            $sums['individual_contributions'] += $individualContributions;
            $sums['debt_recovery'] += $debtRecovery;
            $sums['total_income'] += $totalIncome;
            $sums['operational_administration'] += $operationalAdministration;
            $sums['asset_administration'] += $assetAdministration;
            $sums['total_expenses'] += $totalExpenses;
            $sums['administrative_net'] += $net;

            $cursor->addDay();
        }

        $summary = [
            'project_administration' => $this->decimal($sums['project_administration']),
            'cash_fund_contributions' => $this->decimal($sums['cash_fund_contributions']),
            'individual_contributions' => $this->decimal($sums['individual_contributions']),
            'debt_recovery' => $this->decimal($sums['debt_recovery']),
            'total_income' => $this->decimal($sums['total_income']),
            'operational_administration' => $this->decimal($sums['operational_administration']),
            'asset_administration' => $this->decimal($sums['asset_administration']),
            'total_expenses' => $this->decimal($sums['total_expenses']),
            'administrative_net' => $this->decimal($sums['administrative_net']),
        ];

        return [
            'month' => $month,
            'year' => $year,
            'summary' => $summary,
            'days' => $days,
            'totals' => [
                'project_administration' => $summary['project_administration'],
                'cash_fund_contributions' => $summary['cash_fund_contributions'],
                'individual_contributions' => $summary['individual_contributions'],
                'debt_recovery' => $summary['debt_recovery'],
                'total_income' => $summary['total_income'],
                'operational_administration' => $summary['operational_administration'],
                'asset_administration' => $summary['asset_administration'],
                'total_expenses' => $summary['total_expenses'],
                'net' => $summary['administrative_net'],
            ],
        ];
    }

    /**
     * @return array<string, float>
     */
    private function projectAdministrationByDate(string $start, string $end): array
    {
        $rows = DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->where('projects.administrative_exempt', false)
            ->where('daily_journal_entries.journal_date', '>=', $start)
            ->where('daily_journal_entries.journal_date', '<=', $end)
            ->groupBy('daily_journal_entries.journal_date')
            ->selectRaw('daily_journal_entries.journal_date as journal_date')
            ->selectRaw(
                'COALESCE(SUM('
                .'COALESCE(daily_journal_entries.administrative_fee, 0)'
                .' - COALESCE(daily_journal_entries.administrative_debt, 0)'
                .' + COALESCE(daily_journal_entries.contribution, 0)'
                .'), 0) as project_administration'
            )
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $dateKey = $this->dateKey((string) $row->journal_date);
            $keyed[$dateKey] = round((float) $row->project_administration, 2);
        }

        return $keyed;
    }

    /**
     * @return array<string, float>
     */
    private function debtRecoveryByDate(int $year, int $month): array
    {
        $rows = DB::table('administrative_debt_settlements')
            ->where('year', $year)
            ->where('month', $month)
            ->selectRaw('created_at')
            ->selectRaw(
                'COALESCE(allocated_current_debt, 0) + COALESCE(allocated_carried_debt, 0) as debt_recovery'
            )
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $dateKey = $this->dateKey((string) $row->created_at);
            $keyed[$dateKey] = round(($keyed[$dateKey] ?? 0) + (float) $row->debt_recovery, 2);
        }

        return $keyed;
    }

    /**
     * @return array<string, array{individual_contributions: float, asset_administration: float, notes: ?string}>
     */
    private function manualByDate(string $start, string $end): array
    {
        $rows = AdministrativeFundDay::query()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get(['date', 'individual_contributions', 'asset_administration', 'notes']);

        $keyed = [];
        foreach ($rows as $row) {
            $dateKey = $row->date->toDateString();
            $keyed[$dateKey] = [
                'individual_contributions' => (float) $row->individual_contributions,
                'asset_administration' => (float) $row->asset_administration,
                'notes' => $row->notes,
            ];
        }

        return $keyed;
    }

    /**
     * @return array{
     *     project_administration: float,
     *     cash_fund_contributions: float,
     *     individual_contributions: float,
     *     debt_recovery: float,
     *     total_income: float,
     *     operational_administration: float,
     *     asset_administration: float,
     *     total_expenses: float,
     *     administrative_net: float
     * }
     */
    private function emptyMoneyBag(): array
    {
        return [
            'project_administration' => 0.0,
            'cash_fund_contributions' => 0.0,
            'individual_contributions' => 0.0,
            'debt_recovery' => 0.0,
            'total_income' => 0.0,
            'operational_administration' => 0.0,
            'asset_administration' => 0.0,
            'total_expenses' => 0.0,
            'administrative_net' => 0.0,
        ];
    }

    private function dateKey(string $raw): string
    {
        return strlen($raw) >= 10 ? substr($raw, 0, 10) : $raw;
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
