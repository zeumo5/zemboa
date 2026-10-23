<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('checkout_key_hash', 64)->nullable();
            $table->char('checkout_request_hash', 64)->nullable();
            $table->char('checkout_actor_hash', 64)->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->unique(['store_id', 'checkout_key_hash'], 'orders_checkout_key_unique');
            $table->index(['buyer_user_id', 'store_id']);
        });
    }

    public function down(): void
    {
        // No automatic removal of ownership or retry protection from real orders.
        if (\Illuminate\Support\Facades\DB::table('orders')->whereNotNull('checkout_key_hash')->exists()) {
            throw new RuntimeException('Checkout orders exist; reviewed recovery is required.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_checkout_key_unique');
            $table->dropIndex(['buyer_user_id', 'store_id']);
            $table->dropForeign(['buyer_user_id']);
            $table->dropColumn(['buyer_user_id', 'checkout_key_hash', 'checkout_request_hash', 'checkout_actor_hash', 'checkout_expires_at']);
        });
    }
};
