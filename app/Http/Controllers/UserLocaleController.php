<?php
namespace App\Http\Controllers;


use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

final class UserLocaleController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentification requise.');
        }

        $data = $request->validate([
            'locale' => [
                'required',
                'string',
                Rule::in(['fr', 'en']),
            ],
        ]);

        // Modifier uniquement la préférence
        // de l'utilisateur authentifié.
        //
        // Aucun user_id ou store_id fourni
        // par le client n'est utilisé.
        $user->preferred_locale = $data['locale'];
        $user->save();

        // Appliquer immédiatement la langue
        // pendant cette requête.
        App::setLocale($data['locale']);

        return response()->json([
            'message' => __('ui.language_updated'),
            'locale' => $user->preferred_locale,
        ]);
    }
}
