<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_journal_entries', function (Blueprint $table) {
            $table->decimal('administrative_pool_relief', 15, 2)
                ->default(0)
                ->after('accumulated_administrative_debt');
        });
    }

    public function down(): void
    {
        Schema::table('daily_journal_entries', function (Blueprint $table) {
            $table->dropColumn('administrative_pool_relief');
        });
    }
};
