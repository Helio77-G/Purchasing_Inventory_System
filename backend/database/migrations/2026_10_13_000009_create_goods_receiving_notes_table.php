<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allowed GRN statuses: 'Draft', 'Pending', 'Approved', 'Rejected'
     */
    public function up(): void
    {
        Schema::create('goods_receiving_notes', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number', 64)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('status', 32)->default('Pending')->index();
            $table->string('delivery_note_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status'], 'grn_po_status_idx');
        });

        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('goods_receiving_notes')->restrictOnDelete();
            $table->foreignId('po_item_id')->constrained('po_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('received_quantity', 15, 3);
            $table->decimal('unit_price', 15, 4)->default(0.0000);
            $table->string('uom', 32);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['grn_id', 'po_item_id'], 'grn_po_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grn_items');
        Schema::dropIfExists('goods_receiving_notes');
    }
};
