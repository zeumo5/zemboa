<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('preparation_started_at')->nullable();
            $table->foreignId('preparation_started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ready_at')->nullable();
            $table->foreignId('ready_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['store_id', 'preparation_started_at', 'ready_at'], 'orders_preparation_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('preparation_started_at')->orWhereNotNull('preparation_started_by')->orWhereNotNull('ready_at')->orWhereNotNull('ready_by')->exists()) {
            throw new LogicException('Preparation history exists; rollback refused.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['preparation_started_by']);
            $table->dropForeign(['ready_by']);
            $table->dropIndex('orders_preparation_index');
            $table->dropColumn(['preparation_started_at', 'preparation_started_by', 'ready_at', 'ready_by']);
        });
    }
};
