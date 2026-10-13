
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'mobile_money_reconciliation_events',
            function (Blueprint $table) {
                $table->id();

                // Boutique concernée.
                $table->foreignId('store_id')
                    ->constrained('stores')
                    ->restrictOnDelete();

                // Dossier concerné : déclarer
                // cette colonne UNE SEULE FOIS.
                $table->foreignId('reconciliation_case_id');

                // Nom court pour respecter MySQL.
                $table->foreign(
                    'reconciliation_case_id',
                    'mm_recon_events_case_fk'
                )
                    ->references('id')
                    ->on('mobile_money_reconciliation_cases')
                    ->restrictOnDelete();

                // Responsable de l'événement.
                // NULL pour un événement automatique.
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                // OPENED, RESOLVED, etc.
                $table->string('event_type', 30);

                // Informations administratives.
                $table->json('details')->nullable();

                // Date de création.
                $table->timestamp('created_at')
                    ->useCurrent();

                // Index de consultation.
                $table->index(
                    ['reconciliation_case_id', 'id'],
                    'mm_recon_events_case_idx'
                );

                $table->index(
                    ['store_id', 'created_at'],
                    'mm_recon_events_store_date_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'mobile_money_reconciliation_events'
        );
    }
};
