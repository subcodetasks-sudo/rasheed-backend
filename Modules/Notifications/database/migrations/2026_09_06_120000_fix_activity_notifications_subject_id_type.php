<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `subject` is a polymorphic pointer that can target the User model, whose
     * primary key is a UUID string. The original column was unsignedBigInteger,
     * so creating/editing a user (which dispatches UserCreated/UserUpdated and
     * writes an activity notification with the user as subject) failed with an
     * "Incorrect integer value" DB error. Widen it to a 36-char string, matching
     * the earlier activity_log / model_has_roles fixes.
     */
    public function up(): void
    {
        Schema::table('activity_notifications', function (Blueprint $table) {
            $table->string('subject_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // UUID strings cannot cast to bigint — drop non-numeric rows before reverting.
        DB::table('activity_notifications')
            ->whereNotNull('subject_id')
            ->where('subject_id', 'not regexp', '^[0-9]+$')
            ->delete();

        Schema::table('activity_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
