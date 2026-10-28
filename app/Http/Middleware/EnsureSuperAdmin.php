<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user('web');

        // L'utilisateur doit être authentifié.
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->canUseSuperAdminAccount()) {
            abort(403, 'Identité administrateur non autorisée.');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
