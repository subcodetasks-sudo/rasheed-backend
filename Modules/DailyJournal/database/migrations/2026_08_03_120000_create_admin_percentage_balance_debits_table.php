<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_percentage_balance_debits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_journal_entry_id')
                ->constrained('daily_journal_entries')
                ->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index('daily_journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_percentage_balance_debits');
    }
};
