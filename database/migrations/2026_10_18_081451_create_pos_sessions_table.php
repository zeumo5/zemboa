
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('pos_register_id')
                ->constrained('pos_registers')
                ->restrictOnDelete();

            $table->foreignId('opened_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('closed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 20)
                ->default('OPEN');

            // 1 = session ouverte
            // NULL = session fermée
            $table->unsignedTinyInteger('open_slot')
                ->nullable()
                ->default(1);

            $table->decimal('opening_cash', 12, 2)
                ->default(0);

            $table->decimal('closing_cash_expected', 12, 2)
                ->nullable();

            $table->decimal('closing_cash_counted', 12, 2)
                ->nullable();

            $table->decimal('cash_difference', 12, 2)
                ->nullable();

            $table->timestamp('opened_at')
                ->useCurrent();

            $table->timestamp('closed_at')
                ->nullable();

            $table->timestamps();

            // Une seule session ouverte par caisse.
            $table->unique([
                'pos_register_id',
                'open_slot',
            ]);

            $table->unique(
                ['store_id', 'id'],
                'pos_sessions_store_id_id_unique'
            );
            $table->index([
                'store_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
