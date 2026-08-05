<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_fund_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('individual_contributions', 15, 2)->default(0);
            $table->decimal('asset_administration', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('uuid')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrative_fund_days');
    }
};
