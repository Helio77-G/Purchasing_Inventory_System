<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allowed StockTake status values: 'Draft', 'Approved', 'Cancelled'
     */
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->string('stock_take_number', 64)->unique();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('status', 32)->default('Draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('stock_take_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_id')->constrained('stock_takes')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('system_quantity', 15, 3)->default(0.000);
            $table->decimal('physical_quantity', 15, 3)->default(0.000);
            $table->decimal('variance_quantity', 15, 3)->default(0.000); // physical - system
            $table->decimal('unit_cost', 15, 4)->default(0.0000);
            $table->decimal('variance_cost', 15, 4)->default(0.0000);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['stock_take_id', 'item_id'], 'stock_take_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_take_items');
        Schema::dropIfExists('stock_takes');
    }
};
