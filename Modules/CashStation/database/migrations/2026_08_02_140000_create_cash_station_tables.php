<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_station_month_carries', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('from_year');
            $table->unsignedTinyInteger('from_month');
            $table->unsignedSmallInteger('to_year');
            $table->unsignedTinyInteger('to_month');
            $table->uuid('carried_by')->nullable();
            $table->timestamps();

            $table->unique(['from_year', 'from_month']);
            $table->foreign('carried_by')->references('uuid')->on('users')->nullOnDelete();
        });

        Schema::create('cash_station_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('from_project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('to_project_id')->constrained('projects')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('uuid')->on('users')->nullOnDelete();
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_station_settlements');
        Schema::dropIfExists('cash_station_month_carries');
    }
};
