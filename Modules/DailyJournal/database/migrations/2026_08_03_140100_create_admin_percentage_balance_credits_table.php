<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_percentage_balance_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('administrative_debt_settlement_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->foreign('administrative_debt_settlement_id', 'apb_credits_ads_id_foreign')
                ->references('id')
                ->on('administrative_debt_settlements')
                ->nullOnDelete();
            $table->index('administrative_debt_settlement_id', 'apb_credits_ads_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_percentage_balance_credits');
    }
};
