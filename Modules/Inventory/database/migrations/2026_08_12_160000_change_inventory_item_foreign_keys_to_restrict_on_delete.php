<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('inventory_items')
                ->restrictOnDelete();
        });

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
        });

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('inventory_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();
        });

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
        });

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();
        });
    }
};
