<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $days = DB::table('operational_fund_days')
            ->where('operational_expense', '>', 0)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('operational_fund_expense_items')
                    ->whereColumn(
                        'operational_fund_expense_items.operational_fund_day_id',
                        'operational_fund_days.id',
                    );
            })
            ->get(['id', 'operational_expense', 'updated_by']);

        foreach ($days as $day) {
            DB::table('operational_fund_expense_items')->insert([
                'operational_fund_day_id' => $day->id,
                'amount' => $day->operational_expense,
                'description' => 'مصروف تشغيلي',
                'updated_by' => $day->updated_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('operational_fund_expense_items')
            ->where('description', 'مصروف تشغيلي')
            ->whereIn('operational_fund_day_id', function ($query): void {
                $query->select('id')
                    ->from('operational_fund_days')
                    ->where('operational_expense', '>', 0);
            })
            ->delete();
    }
};
