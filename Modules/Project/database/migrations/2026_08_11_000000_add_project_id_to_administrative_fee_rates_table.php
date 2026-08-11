<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_fee_rates', function (Blueprint $table) {
            $table->dropUnique(['effective_from']);
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'effective_from']);
        });

        // Every project has historically shared the one global rate timeline, so cloning
        // that timeline per project reproduces identical results for all past dates -
        // no historical daily_journal_entries.administrative_fee value is affected.
        $globalRates = DB::table('administrative_fee_rates')->whereNull('project_id')->get(['percentage', 'effective_from']);
        $projectIds = DB::table('projects')->pluck('id');
        $now = now();

        $rows = [];
        foreach ($projectIds as $projectId) {
            foreach ($globalRates as $rate) {
                $rows[] = [
                    'project_id' => $projectId,
                    'percentage' => $rate->percentage,
                    'effective_from' => $rate->effective_from,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('administrative_fee_rates')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('administrative_fee_rates')->whereNotNull('project_id')->delete();

        Schema::table('administrative_fee_rates', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'effective_from']);
            $table->dropConstrainedForeignId('project_id');
            $table->unique('effective_from');
        });
    }
};
