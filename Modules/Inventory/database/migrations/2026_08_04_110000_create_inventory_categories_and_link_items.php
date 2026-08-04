<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('inventory_category_id')
                ->nullable()
                ->after('name')
                ->constrained('inventory_categories')
                ->restrictOnDelete();
        });

        $distinctNames = DB::table('inventory_items')
            ->select('category')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category');

        $now = now();
        foreach ($distinctNames as $name) {
            $categoryId = DB::table('inventory_categories')->insertGetId([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('inventory_items')
                ->where('category', $name)
                ->update(['inventory_category_id' => $categoryId]);
        }

        $orphanIds = DB::table('inventory_items')
            ->whereNull('inventory_category_id')
            ->pluck('id');

        if ($orphanIds->isNotEmpty()) {
            $fallbackId = DB::table('inventory_categories')->insertGetId([
                'name' => 'Uncategorized',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('inventory_items')
                ->whereIn('id', $orphanIds)
                ->update(['inventory_category_id' => $fallbackId]);
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['category']);
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('category')->nullable()->after('name');
        });

        $categories = DB::table('inventory_categories')->pluck('name', 'id');

        foreach ($categories as $id => $name) {
            DB::table('inventory_items')
                ->where('inventory_category_id', $id)
                ->update(['category' => $name]);
        }

        DB::table('inventory_items')
            ->whereNull('category')
            ->update(['category' => 'Uncategorized']);

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['inventory_category_id']);
            $table->dropColumn('inventory_category_id');
            $table->index(['category']);
        });

        Schema::dropIfExists('inventory_categories');
    }
};
