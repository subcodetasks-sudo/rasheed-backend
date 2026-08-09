<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a previous failed run may have already added the column + FK.
        if (! Schema::hasColumn('activity_notifications', 'project_id')) {
            Schema::table('activity_notifications', function (Blueprint $table) {
                $table->foreignId('project_id')
                    ->nullable()
                    ->after('subject_id')
                    ->constrained('projects')
                    ->nullOnDelete();
            });
        }

        $existingProjectIds = DB::table('projects')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $existingProjectIds = array_flip($existingProjectIds);

        DB::table('activity_notifications')
            ->whereNotNull('meta')
            ->whereNull('project_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($existingProjectIds): void {
                foreach ($rows as $row) {
                    $meta = is_string($row->meta) ? json_decode($row->meta, true) : $row->meta;

                    if (! is_array($meta) || ! isset($meta['project_id'])) {
                        continue;
                    }

                    $projectId = (int) $meta['project_id'];

                    // Skip orphaned meta.project_id values (deleted projects).
                    if ($projectId <= 0 || ! isset($existingProjectIds[$projectId])) {
                        continue;
                    }

                    DB::table('activity_notifications')
                        ->where('id', $row->id)
                        ->update(['project_id' => $projectId]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('activity_notifications', 'project_id')) {
            return;
        }

        Schema::table('activity_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
