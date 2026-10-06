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
        Schema::create('stock_reservations', function (Blueprint $table) {
    $table->id();

    $table->foreignId('store_id')
        ->constrained('stores')
        ->restrictOnDelete();

    $table->foreignId('product_variant_id')
        ->constrained('product_variants')
        ->restrictOnDelete();

    $table->unsignedInteger('quantity');

    $table->string('status', 20)
        ->default('ACTIVE');

    $table->string('reference_type', 50)->nullable();
    $table->unsignedBigInteger('reference_id')->nullable();

    $table->timestamp('expires_at')->nullable();
    $table->timestamp('converted_at')->nullable();
    $table->timestamp('released_at')->nullable();

    $table->timestamps();

    $table->index([
        'store_id',
        'product_variant_id',
        'status',
    ]);

    $table->index([
        'reference_type',
        'reference_id',
    ]);

    $table->index([
        'status',
        'expires_at',
    ]);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
