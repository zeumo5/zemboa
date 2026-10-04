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
        Schema::create('products', function (Blueprint $table) {
    $table->id();

    $table->foreignId('store_id')
        ->constrained('stores')
        ->restrictOnDelete();

    $table->foreignId('category_id')
        ->constrained('categories')
        ->restrictOnDelete();

    $table->string('name', 180);
    $table->string('slug', 200);
    $table->text('description')->nullable();

    $table->string('status', 20)->default('ACTIVE');
    $table->boolean('is_featured')->default(false);

    $table->timestamps();

    $table->unique(['store_id', 'slug']);
    $table->index(['store_id', 'status']);
    $table->index(['store_id', 'category_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
