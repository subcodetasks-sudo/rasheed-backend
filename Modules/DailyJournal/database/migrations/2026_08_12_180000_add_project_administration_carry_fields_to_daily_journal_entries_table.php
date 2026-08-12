<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_journal_entries', function (Blueprint $table) {
            $table->decimal('project_administration_settled', 15, 2)
                ->default(0)
                ->after('uncovered_administrative_expense');
            $table->decimal('outstanding_project_administration', 15, 2)
                ->default(0)
                ->after('project_administration_settled');
        });
    }

    public function down(): void
    {
        Schema::table('daily_journal_entries', function (Blueprint $table) {
            $table->dropColumn([
                'project_administration_settled',
                'outstanding_project_administration',
            ]);
        });
    }
};
