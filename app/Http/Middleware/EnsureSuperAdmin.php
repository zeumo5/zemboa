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
        $user = $request->user();

        // L'utilisateur doit être authentifié.
        if (! $user instanceof User) {
            abort(401);
        }

        // Seuls les comptes actifs sont autorisés.
        if ($user->status !== 'ACTIVE') {
            abort(403, 'Compte administrateur inactif.');
        }

        // Un super administrateur est indépendant
        // des boutiques des commerçants.
        if ($user->store_id !== null) {
            abort(403, 'Accès administrateur interdit.');
        }

        // Vérifier le rôle administratif.
        if (! $user->hasRole('SUPER_ADMIN')) {
            abort(403, 'Accès réservé au super administrateur.');
        }

        // Refuser les comptes cumulant des rôles
        // commerçants et super administrateur.
        if (
            $user->roles()
                ->whereIn('code', [
                    'STORE_OWNER',
                    'STORE_MANAGER',
                    'CASHIER',
                    'DELIVERY_AGENT',
                ])
                ->exists()
        ) {
            abort(403, 'Conflit de rôles administratifs.');
        }

        return $next($request);
    }
}
