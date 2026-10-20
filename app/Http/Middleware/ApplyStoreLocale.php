<?php
namespace App\Http\Middleware;


use App\Models\StoreSetting;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class ApplyStoreLocale
{
    private const SUPPORTED = ['fr', 'en'];

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        // La préférence de l'utilisateur connecté
        // reste toujours prioritaire.
        $userLocale = $request->user()?->preferred_locale;

        if ($this->isSupported($userLocale)) {
            return $next($request);
        }

        // Conserver le choix fait par le visiteur.
        $sessionLocale = $request->hasSession()
            ? $request->session()->get('locale')
            : null;

        if ($this->isSupported($sessionLocale)) {
            return $next($request);
        }

        // Le middleware public.tenant doit déjà
        // avoir identifié la boutique visitée.
        $storeId = app(TenantContext::class)->storeId();

        if ($storeId === null) {
            return $next($request);
        }

        // Lire uniquement les paramètres
        // de la boutique actuellement visitée.
        $storeLocale = StoreSetting::query()
            ->where('store_id', $storeId)
            ->value('default_locale');

        // Français si aucun réglage n'existe.
        $locale = $this->isSupported($storeLocale)
            ? $storeLocale
            : 'fr';

        $previousLocale = App::currentLocale();

        App::setLocale($locale);

        try {
            return $next($request);
        } finally {
            // Restaurer la langue précédente.
            // Aucune fuite entre les requêtes.
            App::setLocale($previousLocale);
        }
    }

    private function isSupported(mixed $locale): bool
    {
        return is_string($locale)
            && in_array(
                $locale,
                self::SUPPORTED,
                true
            );
    }
}
