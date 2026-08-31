<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Enums\InventoryBatchSourceType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('opening_price', 15, 2)->default(0)->after('opening_quantity');
        });

        $openingType = InventoryBatchSourceType::Opening->value;

        $batches = DB::table('inventory_batches')
            ->where('source_type', $openingType)
            ->orderBy('id')
            ->get(['inventory_item_id', 'unit_cost']);

        foreach ($batches as $batch) {
            DB::table('inventory_items')
                ->where('id', $batch->inventory_item_id)
                ->update(['opening_price' => $batch->unit_cost]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('opening_price');
        });
    }
};
