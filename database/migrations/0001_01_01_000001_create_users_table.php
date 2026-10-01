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
       Schema::create('users', function (Blueprint $table) {
    $table->id();

    $table->foreignId('store_id')
        ->nullable()
        ->constrained('stores')
        ->restrictOnDelete();

    $table->string('name', 150);
    $table->string('email')->unique();
    $table->string('phone')->nullable();

    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');

    $table->string('status', 20)->default('ACTIVE');
    $table->timestamp('last_login_at')->nullable();

    $table->rememberToken();
    $table->timestamps();
});

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::dropIfExists('sessions');
    Schema::dropIfExists('password_reset_tokens');
    Schema::dropIfExists('users');
}
};
