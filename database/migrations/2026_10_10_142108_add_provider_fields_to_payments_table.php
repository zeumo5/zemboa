
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Prestataire de paiement utilisé
            $table->string('provider', 50)->nullable();

            // Identifiant de transaction chez le prestataire
            $table->string('provider_transaction_id', 150)->nullable();

            // Empêche les doublons de transactions externes
            $table->unique(
                ['provider', 'provider_transaction_id'],
                'payments_provider_transaction_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_provider_transaction_unique');

            $table->dropColumn([
                'provider',
                'provider_transaction_id',
            ]);
        });
    }
};
