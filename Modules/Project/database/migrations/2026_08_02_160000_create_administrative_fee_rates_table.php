<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_fee_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('percentage', 5, 2);
            $table->date('effective_from')->unique();
            $table->timestamps();
        });

        $current = DB::table('settings')
            ->where('key', 'admin_fee_percentage')
            ->value('value');

        $percentage = is_numeric($current) ? round((float) $current, 2) : 12.0;

        DB::table('administrative_fee_rates')->insert([
            'percentage' => $percentage,
            'effective_from' => '2000-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('administrative_fee_rates');
    }
};
