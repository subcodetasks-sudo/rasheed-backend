<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_debt_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->decimal('surplus_at_settlement', 15, 2);
            $table->decimal('recoverable_amount', 15, 2);
            $table->decimal('allocated_cash_box', 15, 2)->default(0);
            $table->decimal('allocated_current_debt', 15, 2)->default(0);
            $table->decimal('allocated_carried_debt', 15, 2)->default(0);
            $table->string('status', 20);
            $table->uuid('settled_by')->nullable();
            $table->timestamps();

            $table->foreign('settled_by')->references('uuid')->on('users')->nullOnDelete();
            $table->index(['year', 'month', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrative_debt_settlements');
    }
};
