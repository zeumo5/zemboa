
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Vérifier les doublons déjà présents.

        $duplicateExists = DB::table(
            'mobile_money_reconciliation_cases'
        )
            ->select(
                'provider',
                'provider_transaction_id'
            )
            ->whereNotNull('provider_transaction_id')
            ->groupBy(
                'provider',
                'provider_transaction_id'
            )
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->get()
            ->isNotEmpty();


        if ($duplicateExists) {
            throw new RuntimeException(
                'Des identifiants de transaction externes '
                    . 'sont déjà utilisés par plusieurs dossiers. '
                    . 'Corrigez les doublons avant la migration.'
            );
        }

        // 2. Ajouter la contrainte d'unicité.
        Schema::table(
            'mobile_money_reconciliation_cases',
            function (Blueprint $table) {
                $table->unique(
                    [
                        'provider',
                        'provider_transaction_id',
                    ],
                    'mm_recon_provider_tx_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'mobile_money_reconciliation_cases',
            function (Blueprint $table) {
                $table->dropUnique(
                    'mm_recon_provider_tx_unique'
                );
            }
        );
    }
};
