
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();

            // Demande de retour concernée.
            $table->foreignId('return_request_id')
                ->constrained('return_requests')
                ->restrictOnDelete();

            // Article provenant de la commande.
            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->restrictOnDelete();

            // Quantité demandée en retour.
            $table->unsignedInteger('quantity');


            // Quantité autorisée à retourner en stock
            // après inspection physique.
            $table->unsignedInteger('restockable_quantity')
                ->nullable();

            // Observations sur l'état des articles.
            $table->text('inspection_notes')->nullable();

            // Protection contre une double remise en stock.
            $table->timestamp('restocked_at')->nullable();


            $table->timestamps();

            // Empêcher les doublons dans une même demande.
            $table->unique([
                'return_request_id',
                'order_item_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');
    }
};
