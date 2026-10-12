<?php
namespace App\Providers;


use App\Payments\MobileMoneyVerifier;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contexte de la boutique active.
        $this->app->scoped(
            TenantContext::class,
            function () {
                return new TenantContext();
            }
        );

        // Sécurité Mobile Money :
        // aucun vérificateur réel n'est encore configuré.
        //
        // Cette liaison empêche Laravel de sélectionner
        // accidentellement un simulateur de paiement.
        $this->app->bind(
            MobileMoneyVerifier::class,
            function (): never {
                throw new LogicException(
                    'Aucun prestataire Mobile Money réel configuré. '
                    . 'Le traitement est désactivé.'
                );
            }
        );
    }

    public function boot(): void
    {
        //
    }
}
