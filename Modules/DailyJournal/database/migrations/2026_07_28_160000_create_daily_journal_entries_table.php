<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('journal_date');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->decimal('daily_income', 15, 2)->nullable();
            $table->decimal('daily_expense', 15, 2)->nullable();
            $table->decimal('contribution', 15, 2)->nullable();
            $table->decimal('administrative_expense', 15, 2)->default(0);
            $table->decimal('administrative_fee', 15, 2)->default(0);
            $table->decimal('operational_deduction', 15, 2)->default(0);
            $table->decimal('daily_total', 15, 2)->default(0);
            $table->decimal('fund_balance', 15, 2)->default(0);
            $table->decimal('administrative_debt', 15, 2)->default(0);
            $table->decimal('accumulated_administrative_debt', 15, 2)->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'journal_date']);
            $table->index('journal_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_journal_entries');
    }
};
