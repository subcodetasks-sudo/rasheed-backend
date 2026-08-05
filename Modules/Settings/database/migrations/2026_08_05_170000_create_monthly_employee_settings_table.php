<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_employee_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('fixed_workers', 15, 2)->default(0);
            $table->decimal('media_staff', 15, 2)->default(0);
            $table->decimal('administrative_staff', 15, 2)->default(0);
            $table->decimal('variable_workers', 15, 2)->default(0);
            $table->decimal('speakers', 15, 2)->default(0);
            $table->decimal('cooks', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_employee_settings');
    }
};
