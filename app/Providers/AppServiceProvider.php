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
        $response = fn () => response(__('buyer.throttled'), 429);
        \Illuminate\Support\Facades\RateLimiter::for('buyer.login', function (\Illuminate\Http\Request $request) use ($response) {
            $identifier = \App\Support\BuyerIdentity::identifier($request->input('identifier', $request->input('email')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('buyer-login:'.$request->ip().'|'.hash('sha256', $identifier))->response($response),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('buyer-login-ip:'.$request->ip())->response($response),
            ];
        });
        \Illuminate\Support\Facades\RateLimiter::for('buyer.register', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('buyer-register:'.$request->ip())->response($response));
    }
}
