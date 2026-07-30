<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('category');
            $table->string('unit');
            $table->decimal('latest_incoming_price', 15, 2)->default(0);
            $table->decimal('opening_quantity', 15, 2)->default(0);
            $table->decimal('total_incoming_quantity', 15, 2)->default(0);
            $table->decimal('total_outgoing_quantity', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->decimal('minimum_stock_level', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('uuid')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('uuid')->on('users')->nullOnDelete();
            $table->index(['category']);
            $table->index(['name']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->foreignId('beneficiary_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('expense_type')->nullable();
            $table->text('notes')->nullable();
            $table->date('movement_date');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('uuid')->on('users')->nullOnDelete();
            $table->index(
                ['type', 'expense_type', 'beneficiary_project_id', 'movement_date'],
                'inv_movements_admin_expense_index'
            );
        });

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('source_type');
            $table->foreignId('source_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('original_quantity', 15, 2);
            $table->decimal('remaining_quantity', 15, 2);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['inventory_item_id', 'received_at', 'id']);
        });

        Schema::create('inventory_batch_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outgoing_movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_cost', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batch_consumptions');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};
