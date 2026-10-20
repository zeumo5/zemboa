
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('pos_session_id');

            // Empêcher une association avec la
            // session d'une autre boutique.
            $table->foreign(
                ['store_id', 'pos_session_id'],
                'pos_cash_movements_store_session_fk'
            )
                ->references(['store_id', 'id'])
                ->on('pos_sessions')
                ->restrictOnDelete();

            // CASH_IN : ajout d'espèces.
            // CASH_OUT : retrait d'espèces.
            $table->string('type', 20);

            $table->decimal('amount', 12, 2);

            // Justification obligatoire.
            $table->string('reason', 255);

            // Référence unique pour éviter
            // l'enregistrement en double.
            $table->string('reference', 64);

            $table->foreignId('recorded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['store_id', 'reference'],
                'pos_cash_movements_store_ref_unique'
            );

            $table->index(
                ['store_id', 'pos_session_id', 'type'],
                'pos_cash_movements_session_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
    }
};
