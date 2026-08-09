<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_notification_reads')) {
            return;
        }

        Schema::create('activity_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')
                ->constrained('activity_notifications')
                ->cascadeOnDelete();
            $table->uuid('user_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('user_id')->references('uuid')->on('users')->cascadeOnDelete();
            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_notification_reads');
    }
};
