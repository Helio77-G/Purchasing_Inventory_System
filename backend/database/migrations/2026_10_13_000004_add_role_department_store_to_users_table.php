<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->after('role_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->after('department_id')->constrained('stores')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('store_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropConstrainedForeignId('store_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('is_active');
        });
    }
};
