<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'administrative_fee_percentage')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('administrative_fee_percentage', 5, 2)
                ->default(12)
                ->after('administrative_exempt');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'administrative_fee_percentage')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('administrative_fee_percentage');
        });
    }
};
