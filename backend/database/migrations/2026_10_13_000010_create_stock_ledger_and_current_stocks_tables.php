<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allowed transaction_type values: 'IN', 'OUT', 'ADJ'
     * Allowed reference_type values: 'GRN', 'STOCK_ISSUE', 'STOCK_ADJUSTMENT' (or morph class)
     */
    public function up(): void
    {
        Schema::create('current_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0.000);
            $table->timestamps();

            $table->unique(['item_id', 'store_id'], 'current_stocks_item_store_unique');
        });

        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('transaction_type', 16)->index(); // IN, OUT, ADJ
            $table->decimal('quantity', 15, 3); // signed quantity delta (+ for IN, - for OUT, +/- for ADJ)
            $table->decimal('balance_after', 15, 3)->nullable();
            $table->string('reference_type', 64);
            $table->unsignedBigInteger('reference_id');
            $table->string('reference_number', 64)->nullable()->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('transaction_at')->useCurrent()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'stock_ledger_ref_idx');
            $table->index(['store_id', 'item_id', 'transaction_at'], 'stock_ledger_store_item_time_idx');
            $table->unique(['reference_type', 'reference_id', 'item_id', 'store_id'], 'stock_ledger_idempotent_ref_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('current_stocks');
    }
};
