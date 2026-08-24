<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Models\CashStationSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;

class ApplyContributionAdministrativeDebtAction
{
    public function __construct(
        private readonly ApplyContributionContributorFundDebitAction $applyContributionContributorFundDebitAction,
    ) {}

    /**
     * Reduce beneficiary accumulated administrative debt and debit contributor fund_balance.
     *
     * Each side applies independently. Missing journal tips leave the matching
     * anchor null for pending retry listeners.
     */
    public function execute(CashStationSettlement $settlement): void
    {
        $amount = round(max(0, (float) $settlement->amount), 2);
        if ($amount <= 0) {
            return;
        }

        $this->applyBeneficiaryDebtReduction($settlement, $amount);
        $this->applyContributionContributorFundDebitAction->execute($settlement);
    }

    private function applyBeneficiaryDebtReduction(CashStationSettlement $settlement, float $amount): void
    {
        if ($settlement->journal_anchor_date !== null) {
            return;
        }

        $endOfMonth = sprintf(
            '%04d-%02d-%02d',
            $settlement->year,
            $settlement->month,
            (int) date('t', mktime(0, 0, 0, $settlement->month, 1, $settlement->year)),
        );

        $anchor = DailyJournalEntry::query()
            ->where('project_id', $settlement->to_project_id)
            ->whereDate('journal_date', '<=', $endOfMonth)
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($anchor === null) {
            return;
        }

        $entries = DailyJournalEntry::query()
            ->where('project_id', $settlement->to_project_id)
            ->whereDate('journal_date', '>=', $anchor->journal_date)
            ->orderBy('journal_date')
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $entry->accumulated_administrative_debt = round(
                max(0, (float) $entry->accumulated_administrative_debt - $amount),
                2,
            );
            $entry->save();
        }

        $settlement->journal_anchor_date = $anchor->journal_date;
        $settlement->save();
    }
}
