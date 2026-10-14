
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();

            // Une seule configuration par boutique
            $table->foreignId('store_id')
                ->unique()
                ->constrained('stores')
                ->cascadeOnDelete();

            // Apparence
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('primary_color', 7)->default('#2563EB');

            // Commandes et réservations
            $table->boolean('allow_reservations')->default(true);
            $table->unsignedInteger('reservation_duration_minutes')
                ->default(1440);

            // Paiements : règles commerciales uniquement
            $table->boolean('allow_partial_payments')->default(true);
            $table->unsignedTinyInteger('minimum_deposit_percent')
                ->default(0);

            // Livraison et retrait
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('pickup_enabled')->default(true);

            // Retours
            $table->boolean('returns_enabled')->default(false);
            $table->unsignedSmallInteger('return_window_days')
                ->default(7);

            // Gestion du stock
            $table->unsignedInteger('low_stock_threshold')
                ->default(5);

            // Horaires d'ouverture
            $table->json('opening_hours')->nullable();

            // Notifications
            $table->boolean('notify_new_orders')->default(true);
            $table->boolean('notify_low_stock')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
