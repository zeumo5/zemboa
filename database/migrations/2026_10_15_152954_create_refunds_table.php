
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // Boutique concernée.
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            // Commande concernée.
            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            // Une seule opération de remboursement
            // par demande de retour.
            $table->foreignId('return_request_id')
                ->unique()
                ->constrained('return_requests')
                ->restrictOnDelete();

            // Montant du remboursement.
            $table->decimal('amount', 12, 2);

            // Méthode prévue pour le remboursement.
            $table->string('method', 30);

            // PENDING, APPROVED, SIMULATED.
            $table->string('status', 30)
                ->default('PENDING');

            // Référence interne de ZEMBOA.
            $table->string('reference', 100)->unique();

            // Commerçant ayant autorisé le remboursement.
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            // Enregistrement de la simulation.
            // Aucun transfert financier réel.
            $table->timestamp('simulated_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
