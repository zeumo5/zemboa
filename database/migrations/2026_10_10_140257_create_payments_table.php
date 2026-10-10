
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Boutique et commande concernées
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            // Montant réellement appliqué à la commande
            $table->decimal('amount', 12, 2);

            // CASH, MTN_MOMO, ORANGE_MONEY
            $table->string('method', 30);

            // PENDING, CONFIRMED, FAILED, CANCELLED
            $table->string('status', 30)->default('PENDING');

            // Référence du prestataire de paiement
            $table->string('reference', 150)->nullable();

            // Caissier ou utilisateur ayant encaissé
            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Informations réservées aux paiements en espèces
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_given', 12, 2)->nullable();

            // Date de confirmation du paiement
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            // Index pour les recherches fréquentes
            $table->index(['store_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index(['store_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
