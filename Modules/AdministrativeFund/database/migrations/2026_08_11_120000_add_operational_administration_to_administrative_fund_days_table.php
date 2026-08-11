<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_fund_days', function (Blueprint $table) {
            $table->decimal('operational_administration', 15, 2)->default(0)->after('asset_administration');
        });

        if (Schema::hasTable('operational_fund_days')) {
            $operationalExpenses = DB::table('operational_fund_days')
                ->select(['date', 'operational_expense'])
                ->get();

            foreach ($operationalExpenses as $row) {
                $existing = DB::table('administrative_fund_days')->where('date', $row->date)->first();

                if ($existing) {
                    DB::table('administrative_fund_days')
                        ->where('date', $row->date)
                        ->update([
                            'operational_administration' => $row->operational_expense,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('administrative_fund_days')->insert([
                        'date' => $row->date,
                        'operational_administration' => $row->operational_expense,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('administrative_fund_days', function (Blueprint $table) {
            $table->dropColumn('operational_administration');
        });
    }
};
