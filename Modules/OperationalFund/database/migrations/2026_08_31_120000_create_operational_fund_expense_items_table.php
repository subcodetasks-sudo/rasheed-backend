<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_fund_expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_fund_day_id')
                ->constrained('operational_fund_days')
                ->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('uuid')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_fund_expense_items');
    }
};
