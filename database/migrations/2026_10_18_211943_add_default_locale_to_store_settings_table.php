
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            // Langue par défaut de la boutique.
            //
            // fr = Français
            // en = English
            //
            // Le commerçant pourra la modifier
            // depuis les paramètres de sa boutique.
            $table->string('default_locale', 5)
                ->default('fr');
        });
    }

    public function down(): void
    {
        // Volontairement non destructif.
        // Conserver les préférences enregistrées.
    }
};
