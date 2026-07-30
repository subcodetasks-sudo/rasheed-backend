<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('fund_type');
            $table->string('status')->default('active');
            $table->string('operational_deduction_type')->default('relative');
            $table->decimal('operational_fixed_amount', 15, 2)->nullable();
            $table->boolean('administrative_exempt')->default(false);
            $table->decimal('administrative_fee_percentage', 5, 2)->default(12);
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('fund_type');
            $table->index('status');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
