<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_deduction_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 15, 2);
            $table->date('effective_from')->unique();
            $table->timestamps();
        });

        $current = DB::table('settings')
            ->where('key', 'total_operational_deduction')
            ->value('value');

        $amount = is_numeric($current) ? round((float) $current, 2) : 1081.0;

        DB::table('operational_deduction_rates')->insert([
            'amount' => $amount,
            'effective_from' => '2000-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_deduction_rates');
    }
};
