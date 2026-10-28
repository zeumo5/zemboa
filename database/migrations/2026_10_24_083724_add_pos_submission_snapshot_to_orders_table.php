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
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->string('customer_name', 150)->nullable()->change();
            $table->string('customer_phone', 30)->nullable()->change();
            $table->string('pos_key_hash', 64)->nullable();
            $table->string('pos_request_hash', 64)->nullable();
            $table->json('pos_document_snapshot')->nullable();
            $table->foreignId('pos_created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['store_id', 'pos_key_hash'], 'orders_pos_submission_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNull('customer_id')->orWhereNull('customer_name')->orWhereNull('customer_phone')->orWhereNotNull('pos_key_hash')->exists()) {
            throw new RuntimeException('POS or anonymous orders exist: reviewed recovery required.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_pos_submission_unique');
            $table->dropConstrainedForeignId('pos_created_by_user_id');
            $table->dropColumn(['pos_key_hash', 'pos_request_hash', 'pos_document_snapshot']);
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->string('customer_name', 150)->nullable(false)->change();
            $table->string('customer_phone', 30)->nullable(false)->change();
        });
    }
};
