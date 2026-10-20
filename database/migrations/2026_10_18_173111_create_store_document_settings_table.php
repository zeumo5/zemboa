
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_document_settings', function (Blueprint $table) {
            $table->id();

            // Un seul paramétrage documentaire par boutique.
            $table->foreignId('store_id')
                ->unique()
                ->constrained('stores')
                ->cascadeOnDelete();

            // Format d'impression des factures :
            // A4, TICKET_58, TICKET_80.
            $table->string('invoice_print_format', 20)
                ->default('A4');

            // Format d'impression des reçus.
            $table->string('receipt_print_format', 20)
                ->default('TICKET_80');

            // Disponibilité des documents numériques.
            $table->boolean('digital_invoices_enabled')
                ->default(true);

            $table->boolean('digital_receipts_enabled')
                ->default(true);

            // Personnalisation des documents.
            $table->boolean('show_logo')
                ->default(true);

            $table->boolean('show_business_details')
                ->default(true);

            $table->string('footer_message', 500)
                ->nullable();

            // null = hériter de la langue choisie
            // selon les préférences utilisateur/boutique.
            // Sinon : fr ou en.
            $table->string('document_locale', 5)
                ->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_document_settings');
    }
};
