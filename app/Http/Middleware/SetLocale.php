<?php
namespace App\Http\Middleware;


use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    private const SUPPORTED_LOCALES = [
        'fr',
        'en',
    ];

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        // Langue initiale de l'application.
        $originalLocale = App::currentLocale();

        // Langue personnelle de l'utilisateur connecté.
        $userLocale = $request->user()?->preferred_locale;

        // Langue choisie par un visiteur,
        // si une session HTTP est disponible.
        $sessionLocale = $request->hasSession()
            ? $request->session()->get('locale')
            : null;

        // Déterminer la langue autorisée.
        $locale = 'fr';

        foreach ([
            $userLocale,
            $sessionLocale,
            config('app.locale', 'fr'),
        ] as $candidate) {
            if (
                is_string($candidate) &&
                in_array(
                    $candidate,
                    self::SUPPORTED_LOCALES,
                    true
                )
            ) {
                $locale = $candidate;
                break;
            }
        }

        // Activer la langue pendant cette requête.
        App::setLocale($locale);

        try {
            return $next($request);
        } finally {
            // Restaurer la langue précédente pour
            // éviter toute fuite entre requêtes.
            App::setLocale($originalLocale);
        }
    }
}
