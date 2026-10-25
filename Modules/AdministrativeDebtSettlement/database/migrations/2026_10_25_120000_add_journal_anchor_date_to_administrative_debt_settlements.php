<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A settlement is applied to the project's latest journal entry on/before its month end (the anchor)
 * and inherited by later entries through the carried balances. Recording that entry's date keeps the
 * overlay on the same entry even when a later day is added inside the settled month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_debt_settlements', function (Blueprint $table) {
            $table->date('journal_anchor_date')->nullable()->after('settled_by');
        });

        DB::table('administrative_debt_settlements')->orderBy('id')->get()->each(function ($settlement) {
            $endOfMonth = sprintf(
                '%04d-%02d-%02d',
                $settlement->year,
                $settlement->month,
                (int) date('t', mktime(0, 0, 0, (int) $settlement->month, 1, (int) $settlement->year)),
            );

            $anchorDate = DB::table('daily_journal_entries')
                ->where('project_id', $settlement->project_id)
                ->whereDate('journal_date', '<=', $endOfMonth)
                ->orderByDesc('journal_date')
                ->value('journal_date');

            if ($anchorDate !== null) {
                DB::table('administrative_debt_settlements')
                    ->where('id', $settlement->id)
                    ->update(['journal_anchor_date' => substr((string) $anchorDate, 0, 10)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('administrative_debt_settlements', function (Blueprint $table) {
            $table->dropColumn('journal_anchor_date');
        });
    }
};
