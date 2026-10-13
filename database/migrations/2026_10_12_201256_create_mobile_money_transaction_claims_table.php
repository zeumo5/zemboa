
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'mobile_money_transaction_claims',
            function (Blueprint $table) {
                $table->id();

                // Boutique concernée.
                $table->foreignId('store_id')
                    ->constrained('stores')
                    ->restrictOnDelete();

                // Paiement propriétaire de la transaction.
                $table->foreignId('payment_id')
                    ->constrained('payments')
                    ->restrictOnDelete();

                // MTN_MOMO ou ORANGE_MONEY.
                $table->string('provider', 50);

                // Identifiant externe obligatoire.
                $table->string(
                    'provider_transaction_id',
                    150
                );

                // CONFIRMATION ou RECONCILIATION.
                $table->string('claim_type', 30);

                $table->timestamps();

                // Une transaction externe ne peut
                // appartenir qu'à un seul paiement.
                $table->unique(
                    [
                        'provider',
                        'provider_transaction_id',
                    ],
                    'mm_claim_provider_tx_unique'
                );

                // Un paiement ne peut réserver
                // qu'un seul identifiant externe.
                $table->unique(
                    'payment_id',
                    'mm_claim_payment_unique'
                );

                // Faciliter les recherches par boutique.
                $table->index(
                    ['store_id', 'claim_type'],
                    'mm_claim_store_type_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'mobile_money_transaction_claims'
        );
    }
};
