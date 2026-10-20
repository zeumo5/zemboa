
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Langue personnelle de l'utilisateur.
            //
            // fr   = Français
            // en   = Anglais
            // null = langue par défaut applicable
            //
            // Nullable pour conserver la compatibilité
            // avec tous les utilisateurs existants.
            $table->string('preferred_locale', 5)
                ->nullable();
        });
    }

    public function down(): void
    {
        // Volontairement non destructif :
        // conserver les préférences linguistiques
        // déjà enregistrées.
    }
};
