<?php

namespace Modules\DailyJournal\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\CarbonInterface;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\DailyJournalCalculationService;
use Modules\DailyJournal\Support\FundBalanceStatus;
use Modules\Project\Models\Project;

class BuildDailyJournalDayTotalsAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    /**
     * Day-wide column totals for all active projects (not just the current page).
     * Missing journal rows use the same zero / previous-tip defaults as the show view.
     *
     * @return array{
     *     daily_income: string,
     *     daily_expense: string,
     *     contribution: string,
     *     administrative_expense: string,
     *     uncovered_administrative_expense: string,
     *     project_administration_settled: string,
     *     outstanding_project_administration: string,
     *     administrative_fee: string,
     *     operational_deduction: string,
     *     daily_total: string,
     *     fund_balance: string,
     *     administrative_debt: string,
     *     accumulated_administrative_debt: string,
     *     administrative_pool_relief: string,
     *     administrative_pool_relief_given: string,
     *     administrative_fee_remaining: string,
     *     status: string
     * }
     */
    public function execute(CarbonInterface $date): array
    {
        $dateString = $date->toDateString();

        $projectIds = Project::query()->active()->orderBy('id')->pluck('id')->all();

        $sums = [
            'daily_income' => 0.0,
            'daily_expense' => 0.0,
            'contribution' => 0.0,
            'administrative_expense' => 0.0,
            'uncovered_administrative_expense' => 0.0,
            'project_administration_settled' => 0.0,
            'outstanding_project_administration' => 0.0,
            'administrative_fee' => 0.0,
            'operational_deduction' => 0.0,
            'daily_total' => 0.0,
            'fund_balance' => 0.0,
            'administrative_debt' => 0.0,
            'accumulated_administrative_debt' => 0.0,
            'administrative_pool_relief' => 0.0,
            'administrative_pool_relief_given' => 0.0,
            'administrative_fee_remaining' => 0.0,
        ];

        if ($projectIds === []) {
            return $this->formatTotals($sums);
        }

        $stored = DailyJournalEntry::query()
            ->whereDate('journal_date', $dateString)
            ->whereIn('project_id', $projectIds)
            ->get()
            ->keyBy('project_id');

        $missingIds = array_values(array_diff($projectIds, $stored->keys()->all()));
        $previous = $missingIds === []
            ? []
            : $this->calculationService->previousBalances($missingIds, $date);

        foreach ($projectIds as $projectId) {
            if ($stored->has($projectId)) {
                $entry = $stored->get($projectId);
                $sums['daily_income'] += (float) ($entry->daily_income ?? 0);
                $sums['daily_expense'] += (float) ($entry->daily_expense ?? 0);
                $sums['contribution'] += (float) ($entry->contribution ?? 0);
                $sums['administrative_expense'] += (float) $entry->administrative_expense;
                $sums['uncovered_administrative_expense'] += (float) $entry->uncovered_administrative_expense;
                $sums['project_administration_settled'] += (float) $entry->project_administration_settled;
                $sums['outstanding_project_administration'] += (float) $entry->outstanding_project_administration;
                $sums['administrative_fee'] += (float) $entry->administrative_fee;
                $sums['operational_deduction'] += (float) $entry->operational_deduction;
                $sums['daily_total'] += (float) $entry->daily_total;
                $sums['fund_balance'] += (float) $entry->fund_balance;
                $sums['administrative_debt'] += (float) $entry->administrative_debt;
                $sums['accumulated_administrative_debt'] += (float) $entry->accumulated_administrative_debt;
                $sums['administrative_pool_relief'] += (float) ($entry->administrative_pool_relief ?? 0);
                $sums['administrative_pool_relief_given'] += (float) ($entry->administrative_pool_relief_given ?? 0);
                $sums['administrative_fee_remaining'] += (float) ($entry->administrative_fee_remaining ?? 0);

                continue;
            }

            $prior = $previous[$projectId] ?? [];
            $sums['outstanding_project_administration'] += (float) ($prior['outstanding_project_administration'] ?? 0);
            $sums['fund_balance'] += (float) ($prior['fund_balance'] ?? 0);
            $sums['accumulated_administrative_debt'] += (float) ($prior['accumulated_administrative_debt'] ?? 0);
        }

        return $this->formatTotals($sums);
    }

    /**
     * @param  array<string, float>  $sums
     * @return array<string, string>
     */
    private function formatTotals(array $sums): array
    {
        return [
            'daily_income' => FormatMoneyDecimal::format($sums['daily_income']),
            'daily_expense' => FormatMoneyDecimal::format($sums['daily_expense']),
            'contribution' => FormatMoneyDecimal::format($sums['contribution']),
            'administrative_expense' => FormatMoneyDecimal::formatRounded($sums['administrative_expense']),
            'uncovered_administrative_expense' => FormatMoneyDecimal::formatRounded($sums['uncovered_administrative_expense']),
            'project_administration_settled' => FormatMoneyDecimal::formatRounded($sums['project_administration_settled']),
            'outstanding_project_administration' => FormatMoneyDecimal::formatRounded($sums['outstanding_project_administration']),
            'administrative_fee' => FormatMoneyDecimal::formatRounded($sums['administrative_fee']),
            'operational_deduction' => FormatMoneyDecimal::formatRounded($sums['operational_deduction']),
            'daily_total' => FormatMoneyDecimal::formatRounded($sums['daily_total']),
            'fund_balance' => FormatMoneyDecimal::formatRounded($sums['fund_balance']),
            'administrative_debt' => FormatMoneyDecimal::formatRounded($sums['administrative_debt']),
            'accumulated_administrative_debt' => FormatMoneyDecimal::formatRounded($sums['accumulated_administrative_debt']),
            'administrative_pool_relief' => FormatMoneyDecimal::formatRounded($sums['administrative_pool_relief']),
            'administrative_pool_relief_given' => FormatMoneyDecimal::formatRounded($sums['administrative_pool_relief_given']),
            'administrative_fee_remaining' => FormatMoneyDecimal::formatRounded($sums['administrative_fee_remaining']),
            'status' => FundBalanceStatus::from($sums['fund_balance']),
        ];
    }
}
