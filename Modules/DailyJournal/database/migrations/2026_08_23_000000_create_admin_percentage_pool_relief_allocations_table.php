<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Explicit short constraint/index names below: the default auto-generated
        // "{table}_{column}_foreign" names exceed MySQL's 64-char identifier limit
        // for this table name + these column names.
        Schema::create('admin_percentage_pool_relief_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relief_entry_id')
                ->constrained('daily_journal_entries', indexName: 'apra_relief_entry_fk')
                ->cascadeOnDelete();
            $table->foreignId('source_project_id')
                ->constrained('projects', indexName: 'apra_source_project_fk')
                ->cascadeOnDelete();
            $table->foreignId('beneficiary_project_id')
                ->constrained('projects', indexName: 'apra_beneficiary_project_fk')
                ->cascadeOnDelete();
            $table->date('journal_date');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index('journal_date', 'apra_journal_date_idx');
            $table->index(['beneficiary_project_id', 'journal_date'], 'apra_beneficiary_date_idx');
            $table->index(['source_project_id', 'journal_date'], 'apra_source_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_percentage_pool_relief_allocations');
    }
};
