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
       Schema::create('product_variants', function (Blueprint $table) {
    $table->id();

    $table->foreignId('store_id')
        ->constrained('stores')
        ->restrictOnDelete();

    $table->foreignId('product_id')
        ->constrained('products')
        ->restrictOnDelete();

    $table->string('sku', 100);

    $table->decimal('price', 12, 2);

    $table->decimal('promo_price', 12, 2)
        ->nullable();

    $table->timestamp('promo_starts_at')
        ->nullable();

    $table->timestamp('promo_ends_at')
        ->nullable();

    $table->boolean('is_default')
        ->default(false);

    $table->string('status', 20)
        ->default('ACTIVE');

    $table->timestamps();

    $table->unique(['store_id', 'sku']);

    $table->index(['store_id', 'product_id']);
    $table->index(['store_id', 'status']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
