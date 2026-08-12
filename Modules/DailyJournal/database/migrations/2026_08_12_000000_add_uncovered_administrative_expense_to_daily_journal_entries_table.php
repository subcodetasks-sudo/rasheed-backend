<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_journal_entries', function (Blueprint $table) {
            $table->decimal('uncovered_administrative_expense', 15, 2)
                ->default(0)
                ->after('administrative_expense');
        });
    }

    public function down(): void
    {
        Schema::table('daily_journal_entries', function (Blueprint $table) {
            $table->dropColumn('uncovered_administrative_expense');
        });
    }
};
