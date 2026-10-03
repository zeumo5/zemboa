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
    Schema::create('categories', function (Blueprint $table) {
        $table->id();

        $table->foreignId('store_id')
            ->constrained('stores')
            ->restrictOnDelete();

        $table->string('name', 150);
        $table->string('slug', 180);
        $table->text('description')->nullable();
        $table->string('status', 20)->default('ACTIVE');

        $table->timestamps();

        $table->unique(['store_id', 'slug']);
        $table->index(['store_id', 'status']);
    });
}

    /**
     * Reverse the migrations.
     */
   public function down(): void
{
    Schema::dropIfExists('categories');
}
};
