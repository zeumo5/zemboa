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
       Schema::create('attribute_values', function (Blueprint $table) {
    $table->id();

    $table->foreignId('store_id')
        ->constrained('stores')
        ->restrictOnDelete();

    $table->foreignId('attribute_id')
        ->constrained('attributes')
        ->restrictOnDelete();

    $table->string('value', 120);

    $table->timestamps();

    $table->unique(['attribute_id', 'value']);
    $table->index(['store_id', 'attribute_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};
