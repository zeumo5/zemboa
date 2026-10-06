<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->string('type', 30);

            // Toujours positive.
            // Le type du mouvement détermine s'il s'agit d'une entrée
            // ou d'une sortie de stock.
            $table->unsignedInteger('quantity');

            // Permettra de rattacher le mouvement à une commande,
            // un retour, une réception, etc.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('reason', 255)->nullable();

            // L'utilisateur ayant provoqué/enregistré le mouvement.
            // Nullable pour permettre certains traitements système.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Un mouvement est historique et immuable :
            // pas de updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index([
                'store_id',
                'product_variant_id',
                'created_at',
            ]);

            $table->index([
                'reference_type',
                'reference_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};