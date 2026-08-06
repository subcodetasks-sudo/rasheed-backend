<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('causer_id', 36)->nullable()->change();
            $table->string('subject_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // UUID strings cannot cast to bigint — clear rows before reverting type.
        DB::table('activity_log')->delete();

        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('causer_id')->nullable()->change();
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
