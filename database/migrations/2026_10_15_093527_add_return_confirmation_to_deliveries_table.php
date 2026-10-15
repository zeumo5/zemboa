
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable();

            $table->foreignId('return_confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('return_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['return_confirmed_by']);

            $table->dropColumn([
                'returned_at',
                'return_confirmed_by',
                'return_notes',
            ]);
        });
    }
};
