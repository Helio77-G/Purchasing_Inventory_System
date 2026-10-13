<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allowed types: 'SR' (Store Requisition), 'PR' (Purchase Requisition)
     * Allowed SR statuses: 'Pending', 'HOD Approved', 'Partially Issued', 'Issued', 'Rejected'
     * Allowed PR statuses: 'Pending', 'HOD Approved', 'GM Approved', 'PO Generated', 'Rejected'
     */
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_number', 64)->unique();
            $table->string('type', 10)->index(); // SR or PR
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('Pending')->index();
            $table->foreignId('hod_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('hod_approved_at')->nullable();
            $table->foreignId('gm_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('gm_approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'department_id'], 'req_type_status_dept_idx');
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('requested_quantity', 15, 3);
            $table->decimal('issued_quantity', 15, 3)->default(0.000);
            $table->string('uom', 32);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['requisition_id', 'item_id'], 'req_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
    }
};
