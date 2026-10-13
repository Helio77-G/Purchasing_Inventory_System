<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allowed StockIssue status values: 'Draft', 'Posted'
     */
    public function up(): void
    {
        Schema::create('stock_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number', 64)->unique();
            $table->foreignId('requisition_id')->constrained('requisitions')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->string('status', 32)->default('Draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['requisition_id', 'status']);
            $table->index(['store_id', 'status']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('stock_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_issue_id')->constrained('stock_issues')->cascadeOnDelete();
            $table->foreignId('requisition_item_id')->constrained('requisition_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('issued_quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->default(0.0000);
            $table->decimal('line_cost', 15, 4)->default(0.0000);
            $table->string('uom', 32);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['stock_issue_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_issue_items');
        Schema::dropIfExists('stock_issues');
    }
};
