<?php
namespace App\Http\Controllers\Auth;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    private const STORE_ROLES = [
        'STORE_OWNER',
        'STORE_MANAGER',
        'CASHIER',
        'DELIVERY_AGENT',
    ];

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        Request $request
    ): JsonResponse|RedirectResponse {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $authenticated = Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => 'ACTIVE',
        ]);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants incorrects.',
            ]);
        }

        $user = $request->user();

        $isStoreUser =
            $user instanceof User &&
            $user->store_id !== null &&
            $user->store !== null &&
            $user->store->status === 'ACTIVE' &&
            $user->roles()
                ->whereIn('code', self::STORE_ROLES)
                ->exists() &&
            ! $user->hasRole('SUPER_ADMIN');

        $isEmployee = $user instanceof User &&
            $user->roles()
                ->whereIn('code', [
                    'STORE_MANAGER',
                    'CASHIER',
                    'DELIVERY_AGENT',
                ])
                ->exists() &&
            ! $user->hasRole('STORE_OWNER');

        if (
            ! $isStoreUser ||
            ($isEmployee && $user->email_verified_at === null)
        ) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Connexion non autorisée.',
            ]);
        }

        // Empêche la fixation de session.
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Connexion réussie.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
            ]);
        }

        return redirect()->intended(
            route('account.show')
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'store_id' => $user->store_id,
            'roles' => $user->roles()
                ->pluck('code'),
        ]);
    }

    public function destroy(
        Request $request
    ): JsonResponse|RedirectResponse {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Déconnexion réussie.',
            ]);
        }

        return redirect()->route('login');
    }
}
