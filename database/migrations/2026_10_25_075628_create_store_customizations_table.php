<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('store_customizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->restrictOnDelete();
            $table->json('draft')->nullable();
            $table->string('base_hash', 64)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::table('store_settings', function (Blueprint $table) {
            $table->boolean('commercial_configured')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('store_customizations')->exists()
            || DB::table('store_settings')->whereNotNull('commercial_configured')->exists()) {
            throw new RuntimeException('Store customization data must be preserved; rollback refused.');
        }
        Schema::dropIfExists('store_customizations');
        Schema::table('store_settings', fn (Blueprint $table) => $table->dropColumn('commercial_configured'));
    }
};
