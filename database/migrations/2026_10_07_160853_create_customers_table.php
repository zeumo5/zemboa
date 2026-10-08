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
       Schema::create('customers', function (Blueprint $table) {
    $table->id();

    $table->foreignId('store_id')
        ->constrained('stores')
        ->restrictOnDelete();

    $table->string('name', 150);
    $table->string('phone', 30);
    $table->string('email', 150)->nullable();
    $table->string('status', 30)->default('ACTIVE');

    $table->timestamps();

    $table->unique(['store_id', 'phone']);
    $table->index(['store_id', 'status']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
