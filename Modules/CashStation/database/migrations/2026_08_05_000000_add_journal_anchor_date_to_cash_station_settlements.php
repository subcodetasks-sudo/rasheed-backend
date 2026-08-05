<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_station_settlements', function (Blueprint $table) {
            $table->date('journal_anchor_date')->nullable()->after('contribution_type');
        });
    }

    public function down(): void
    {
        Schema::table('cash_station_settlements', function (Blueprint $table) {
            $table->dropColumn('journal_anchor_date');
        });
    }
};
