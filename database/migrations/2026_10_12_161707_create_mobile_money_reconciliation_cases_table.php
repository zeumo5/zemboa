
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'mobile_money_reconciliation_cases',
            function (Blueprint $table) {

                $table->id();

                // Boutique propriétaire du dossier.
                $table->foreignId('store_id')
                    ->constrained('stores')
                    ->restrictOnDelete();

                // Paiement concerné.
                $table->foreignId('payment_id')
                    ->constrained('payments')
                    ->restrictOnDelete();

                // Prestataire et référence interne.
                $table->string('provider', 30);

                $table->string(
                    'payment_reference',
                    150
                );

                // Identifiant externe éventuellement reçu.
                $table->string(
                    'provider_transaction_id',
                    150
                )->nullable();

                // Compte marchand bénéficiaire déclaré.
                $table->string(
                    'recipient_merchant_account_id',
                    150
                )->nullable();

                // Montant concerné.
                $table->decimal('amount', 12, 2);

                $table->char('currency', 3)
                    ->default('XAF');

                // Statut du paiement au moment du constat.
                $table->string(
                    'original_payment_status',
                    30
                );

                // Statut signalé par le prestataire.
                $table->string(
                    'reported_provider_status',
                    30
                );

                // OPEN, UNDER_REVIEW ou RESOLVED.
                $table->string('status', 30)
                    ->default('OPEN');

                // Motif de la contradiction.
                $table->text('reason')->nullable();

                // Notes de résolution.
                $table->text(
                    'resolution_notes'
                )->nullable();

                // Clôture du dossier.
                $table->timestamp(
                    'resolved_at'
                )->nullable();

                $table->foreignId('resolved_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                // Un dossier principal par paiement.
                $table->unique(
                    'payment_id',
                    'mm_recon_payment_unique'
                );

                // Recherche par boutique et statut.
                $table->index(
                    ['store_id', 'status'],
                    'mm_recon_store_status_idx'
                );

                // Recherche des identifiants externes.
                $table->index(
                    ['provider', 'provider_transaction_id'],
                    'mm_recon_provider_tx_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'mobile_money_reconciliation_cases'
        );
    }
};
