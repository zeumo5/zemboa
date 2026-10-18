<?php
namespace App\Http\Controllers\Admin\Auth;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminSessionController extends Controller
{
    private const STORE_ROLES = [
        'STORE_OWNER',
        'STORE_MANAGER',
        'CASHIER',
        'DELIVERY_AGENT',
    ];

    public function create(): View
    {
        return view('admin.login');
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
                'email' => 'Identifiants incorrects ou accès refusé.',
            ]);
        }

        $user = $request->user();

        $isSuperAdmin =
            $user instanceof User &&
            $user->status === 'ACTIVE' &&
            $user->store_id === null &&
            $user->hasRole('SUPER_ADMIN') &&
            ! $user->roles()
                ->whereIn('code', self::STORE_ROLES)
                ->exists();

        if (! $isSuperAdmin) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Identifiants incorrects ou accès refusé.',
            ]);
        }

        // Renouveler la session après connexion.
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Connexion administrateur réussie.',
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
        }

        return redirect()->route('admin.dashboard');
    }

    public function dashboard(Request $request): View
    {
        return view('admin.dashboard', [
            'admin' => $request->user(),
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
                'message' => 'Déconnexion administrateur réussie.',
            ]);
        }

        return redirect()->route('admin.login');
    }
}
