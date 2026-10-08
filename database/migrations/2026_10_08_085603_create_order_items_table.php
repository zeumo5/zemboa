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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            // Snapshot du produit au moment de la commande
            $table->string('product_name', 150);
            $table->string('variant_description', 255)->nullable();
            $table->string('sku', 100);

            // Snapshot financier
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('final_unit_price', 12, 2);

            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
