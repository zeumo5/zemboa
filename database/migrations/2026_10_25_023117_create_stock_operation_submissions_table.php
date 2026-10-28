<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_operation_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->unique(['store_id', 'key_hash'], 'stock_submission_store_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_operation_submissions');
    }
};
