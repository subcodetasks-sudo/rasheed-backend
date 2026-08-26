<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_month_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamp('frozen_at');
            $table->uuid('frozen_by')->nullable();
            $table->boolean('carried_forward_from_previous')->default(false);
            $table->date('calculation_date')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->foreign('frozen_by')->references('uuid')->on('users')->nullOnDelete();
        });

        Schema::create('financial_month_project_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('project_name');
            $table->string('project_type')->nullable();
            $table->string('project_status')->nullable();

            $table->decimal('previous_monthly_total', 15, 2)->default(0);
            $table->decimal('monthly_revenue', 15, 2)->default(0);
            $table->decimal('monthly_expenses', 15, 2)->default(0);
            $table->decimal('administrative_percentage', 15, 2)->default(0);
            $table->decimal('operational_deduction', 15, 2)->default(0);
            $table->decimal('monthly_total', 15, 2)->default(0);
            $table->decimal('cs_administrative_debt', 15, 2)->default(0);
            $table->decimal('remaining_administrative_debt', 15, 2)->default(0);
            $table->decimal('cs_added_contribution', 15, 2)->default(0);
            $table->decimal('cs_deducted_contribution', 15, 2)->default(0);
            $table->decimal('net_cash_fund', 15, 2)->default(0);
            $table->string('cash_status');

            $table->decimal('fund_balance_tip', 15, 2)->default(0);
            $table->decimal('ms_administrative_debt', 15, 2)->default(0);
            $table->decimal('ms_added_contribution', 15, 2)->default(0);
            $table->decimal('ms_deducted_contribution', 15, 2)->default(0);

            $table->timestamps();

            $table->unique(['year', 'month', 'project_id']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_month_project_snapshots');
        Schema::dropIfExists('financial_month_locks');
    }
};
