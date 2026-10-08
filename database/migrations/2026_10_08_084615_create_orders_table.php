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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->string('order_number', 50)->unique();

            $table->string('status', 30)->default('PENDING');
            $table->string('payment_status', 30)->default('UNPAID');
            $table->string('fulfillment_type', 20);

            // Snapshot du client au moment de la commande
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30);
            $table->string('customer_email', 150)->nullable();

            // Snapshot de la livraison
            $table->string('delivery_zone_name', 150)->nullable();
            $table->string('delivery_city', 100)->nullable();
            $table->string('delivery_area', 150)->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('delivery_instructions')->nullable();

            // Montants
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            // Échéances
            $table->timestamp('reservation_expires_at')->nullable();
            $table->timestamp('balance_due_at')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'payment_status']);
            $table->index(['store_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
