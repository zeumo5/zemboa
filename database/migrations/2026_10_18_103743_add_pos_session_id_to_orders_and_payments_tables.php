
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable();

            $table->index(
                ['store_id', 'pos_session_id'],
                'orders_store_pos_session_idx'
            );

            $table->foreign(
                ['store_id', 'pos_session_id'],
                'orders_store_pos_session_fk'
            )
                ->references(['store_id', 'id'])
                ->on('pos_sessions')
                ->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable();

            $table->index(
                ['store_id', 'pos_session_id'],
                'payments_store_pos_session_idx'
            );

            $table->foreign(
                ['store_id', 'pos_session_id'],
                'payments_store_pos_session_fk'
            )
                ->references(['store_id', 'id'])
                ->on('pos_sessions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_store_pos_session_fk');
            $table->dropIndex('payments_store_pos_session_idx');
            $table->dropColumn('pos_session_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_store_pos_session_fk');
            $table->dropIndex('orders_store_pos_session_idx');
            $table->dropColumn('pos_session_id');
        });
    }
};
