
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_status_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('old_status', 20);
            $table->string('new_status', 20);

            // Motif de la décision administrative.
            $table->string('reason', 500);

            // Date exacte de l'opération.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_status_events');
    }
};
