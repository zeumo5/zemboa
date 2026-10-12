
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Compte marchand bénéficiaire du paiement.
            $table->foreignId('store_payment_account_id')
                ->nullable()
                ->constrained('store_payment_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId(
                'store_payment_account_id'
            );
        });
    }
};
