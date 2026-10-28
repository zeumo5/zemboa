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

        $authenticated = Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => 'ACTIVE',
        ]);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => __('access.access_denied'),
            ]);
        }

        $user = $request->user('web');

        $isSuperAdmin =
            $user instanceof User &&
            $user->canUseSuperAdminAccount();

        if (! $isSuperAdmin) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('access.access_denied'),
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

    public function destroy(
        Request $request
    ): JsonResponse|RedirectResponse {
        Auth::guard('web')->logout();

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
