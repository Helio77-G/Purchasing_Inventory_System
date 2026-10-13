<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allowed PO statuses: 'Draft', 'Sent', 'Partial Received', 'Fully Received', 'Cancelled'
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 64)->unique();
            $table->foreignId('requisition_id')->nullable()->constrained('requisitions')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 32)->default('Draft')->index();
            $table->decimal('total_amount', 15, 4)->default(0.0000);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'supplier_id'], 'po_status_supplier_idx');
        });

        Schema::create('po_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('requisition_item_id')->nullable()->constrained('requisition_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('ordered_quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0.000);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('line_total', 15, 4);
            $table->string('uom', 32);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'item_id'], 'po_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('po_items');
        Schema::dropIfExists('purchase_orders');
    }
};
