
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_accounts', function (Blueprint $table) {
            $table->id();

            // Chaque compte appartient à une boutique.
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            // MTN_MOMO ou ORANGE_MONEY.
            $table->string('method', 30);

            // Passerelle utilisée, une fois choisie.
            // Exemples : intégration directe ou agrégateur.
            $table->string('gateway', 50)->nullable();

            // Identifiant du compte marchand chez le prestataire.
            $table->string('merchant_account_id', 150)->nullable();

            // Numéro associé au portefeuille du commerçant.
            $table->string('wallet_number', 25)->nullable();

            // Un compte doit être vérifié avant son activation.
            $table->string('status', 30)
                ->default('PENDING_VERIFICATION');

            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // Un compte configuré par moyen de paiement
            // pour chaque boutique.
            $table->unique(
                ['store_id', 'method'],
                'store_payment_method_unique'
            );

            // Empêcher de rattacher le même compte externe
            // renseigné chez une passerelle à deux boutiques.
            $table->unique(
                ['gateway', 'merchant_account_id'],
                'gateway_merchant_account_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_accounts');
    }
};
