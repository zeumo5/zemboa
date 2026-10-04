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
       Schema::create('product_variant_attribute_value', function (Blueprint $table) {
    $table->id();

    $table->foreignId('product_variant_id')
        ->constrained('product_variants')
        ->cascadeOnDelete();

    $table->foreignId('attribute_value_id')
        ->constrained('attribute_values')
        ->restrictOnDelete();

    $table->timestamps();

    $table->unique(
    ['product_variant_id', 'attribute_value_id'],
    'variant_attribute_value_unique'
);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_value');
    }
};
