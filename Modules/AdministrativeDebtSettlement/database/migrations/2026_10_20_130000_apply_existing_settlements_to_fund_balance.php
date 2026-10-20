<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settlements now deduct their recoverable_amount from the journal fund_balance (Net Cash) tip.
 * Settlements recorded before that change never did, and the surplus calculation no longer
 * subtracts prior settlements, so apply the missing deduction once to the existing ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shiftFundBalance(-1);
    }

    public function down(): void
    {
        $this->shiftFundBalance(1);
    }

    private function shiftFundBalance(int $direction): void
    {
        DB::transaction(function () use ($direction) {
            $settlements = DB::table('administrative_debt_settlements')->orderBy('id')->get();

            foreach ($settlements as $settlement) {
                $amount = round((float) $settlement->recoverable_amount, 2);
                if ($amount <= 0) {
                    continue;
                }

                $endOfMonth = sprintf(
                    '%04d-%02d-%02d',
                    $settlement->year,
                    $settlement->month,
                    (int) date('t', mktime(0, 0, 0, (int) $settlement->month, 1, (int) $settlement->year)),
                );

                $anchor = DB::table('daily_journal_entries')
                    ->where('project_id', $settlement->project_id)
                    ->whereDate('journal_date', '<=', $endOfMonth)
                    ->orderByDesc('journal_date')
                    ->orderByDesc('id')
                    ->first(['id', 'journal_date']);

                if ($anchor === null) {
                    continue;
                }

                DB::table('daily_journal_entries')
                    ->where('project_id', $settlement->project_id)
                    ->where(function ($query) use ($anchor) {
                        $query->whereDate('journal_date', '>', $anchor->journal_date)
                            ->orWhere(function ($sameDay) use ($anchor) {
                                $sameDay->whereDate('journal_date', $anchor->journal_date)
                                    ->where('id', '>=', $anchor->id);
                            });
                    })
                    ->update([
                        'fund_balance' => DB::raw('fund_balance + ('.($direction * $amount).')'),
                    ]);
            }
        });
    }
};
