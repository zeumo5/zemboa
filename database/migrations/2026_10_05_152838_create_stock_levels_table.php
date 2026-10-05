<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

           $table->foreignId('product_variant_id')
    ->constrained('product_variants')
    ->cascadeOnDelete();

            $table->unsignedInteger('physical_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);

            $table->timestamps();

            // Une variante ne possède qu'un seul état de stock.
            $table->unique('product_variant_id');

            // Requêtes fréquentes du stock d'une boutique.
            $table->index(['store_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};