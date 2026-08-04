<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_station_settlements', function (Blueprint $table) {
            $table->string('contribution_type', 32)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_station_settlements', function (Blueprint $table) {
            $table->dropColumn('contribution_type');
        });
    }
};
