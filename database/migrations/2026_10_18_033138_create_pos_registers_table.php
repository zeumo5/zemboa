
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->string('name', 100);
            $table->string('code', 40);

            $table->string('status', 20)
                ->default('ACTIVE');

            $table->timestamps();

            // Un même code ne peut pas être utilisé
            // deux fois dans une boutique.
            $table->unique(['store_id', 'code']);

            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_registers');
    }
};
