<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'admin_fee_percentage')) {
                $table->decimal('admin_fee_percentage', 5, 2)->default(12)->after('is_public');
            }

            if (! Schema::hasColumn('settings', 'total_operational_deduction')) {
                $table->decimal('total_operational_deduction', 15, 2)->default(1081)->after('admin_fee_percentage');
            }
        });

        $adminFee = DB::table('settings')->where('key', 'admin_fee_percentage')->value('value');
        $totalOperational = DB::table('settings')->where('key', 'total_operational_deduction')->value('value');

        DB::table('settings')->update([
            'admin_fee_percentage' => is_numeric($adminFee) ? $adminFee : 12,
            'total_operational_deduction' => is_numeric($totalOperational) ? $totalOperational : 1081,
        ]);

        DB::table('settings')->whereIn('key', [
            'admin_fee_percentage',
            'total_operational_deduction',
        ])->delete();
    }

    public function down(): void
    {
        $row = DB::table('settings')->first();

        if ($row) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'admin_fee_percentage'],
                [
                    'value' => $row->admin_fee_percentage ?? 12,
                    'type' => 'decimal',
                    'is_public' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('settings')->updateOrInsert(
                ['key' => 'total_operational_deduction'],
                [
                    'value' => $row->total_operational_deduction ?? 1081,
                    'type' => 'decimal',
                    'is_public' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'total_operational_deduction')) {
                $table->dropColumn('total_operational_deduction');
            }

            if (Schema::hasColumn('settings', 'admin_fee_percentage')) {
                $table->dropColumn('admin_fee_percentage');
            }
        });
    }
};
