<?php
namespace App\Http\Controllers;


use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

final class GuestLocaleController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        // Les utilisateurs connectés disposent déjà
        // de PATCH /account/locale.
        if ($request->user() !== null) {
            throw new AuthorizationException(
                'Utilisez les préférences de votre compte.'
            );
        }

        $data = $request->validate([
            'locale' => [
                'required',
                'string',
                Rule::in(['fr', 'en']),
            ],
        ]);

        // Enregistrer uniquement la langue du visiteur.
        // Ne pas supprimer son panier ou sa session.
        $request->session()->put(
            'locale',
            $data['locale']
        );

        // Appliquer immédiatement la langue choisie.
        App::setLocale($data['locale']);

        return response()->json([
            'message' => __('ui.language_updated'),
            'locale' => $data['locale'],
        ]);
    }
}
