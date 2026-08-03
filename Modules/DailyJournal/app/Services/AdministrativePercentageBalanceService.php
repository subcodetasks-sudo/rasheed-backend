<?php

namespace Modules\DailyJournal\Services;

use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\DailyJournal\Models\AdminPercentageBalanceCredit;
use Modules\DailyJournal\Models\AdminPercentageBalanceDebit;
use Modules\DailyJournal\Models\DailyJournalEntry;

/**
 * Organization Administrative Percentage Balance pool.
 *
 * available = SUM(administrative_fee) − SUM(permanent contribution debits) + SUM(debt-settlement credits)
 *
 * Debits are non-refundable: lowering/clearing a journal contribution never
 * reduces prior debits. Credits permanently increase the pool when admin debt
 * is recovered via Administrative Debt Settlement.
 */
class AdministrativePercentageBalanceService
{
    public function availableBalance(): float
    {
        $fees = (float) DailyJournalEntry::query()
            ->selectRaw('COALESCE(SUM(COALESCE(administrative_fee, 0)), 0) as total')
            ->value('total');

        $debits = (float) AdminPercentageBalanceDebit::query()
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $credits = (float) AdminPercentageBalanceCredit::query()
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        return round(max(0, $fees - $debits + $credits), 2);
    }

    public function consumedForEntry(?int $journalEntryId): float
    {
        if ($journalEntryId === null) {
            return 0.0;
        }

        return round((float) AdminPercentageBalanceDebit::query()
            ->where('daily_journal_entry_id', $journalEntryId)
            ->sum('amount'), 2);
    }

    /**
     * Permanently debit any increase in contribution above what was already consumed.
     * Never credits the pool when contribution decreases.
     */
    public function syncDebitForContribution(DailyJournalEntry $entry, float $contributionAmount): void
    {
        $contributionAmount = round(max(0, $contributionAmount), 2);
        $alreadyConsumed = $this->consumedForEntry($entry->id);
        $additional = round($contributionAmount - $alreadyConsumed, 2);

        if ($additional <= 0) {
            return;
        }

        AdminPercentageBalanceDebit::query()->create([
            'daily_journal_entry_id' => $entry->id,
            'amount' => $additional,
        ]);
    }

    /**
     * Permanently credit the pool when administrative debt is recovered via settlement.
     */
    public function creditFromDebtSettlement(AdministrativeDebtSettlement $settlement, float $amount): AdminPercentageBalanceCredit
    {
        $amount = round(max(0, $amount), 2);

        return AdminPercentageBalanceCredit::query()->create([
            'administrative_debt_settlement_id' => $settlement->id,
            'amount' => $amount,
        ]);
    }
}
