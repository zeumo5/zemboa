<?php

namespace App\Http\Middleware;


use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        // Authentification obligatoire.
        if ($user === null) {
            abort(401);
        }

        // Vérifier le statut du compte utilisateur.
        // Seuls les comptes ACTIVE peuvent accéder
        // aux routes privées des boutiques.
        if ($user->status !== 'ACTIVE') {
            abort(
                403,
                'Ce compte utilisateur est désactivé.'
            );
        }


        // Vérifier la présence d'une boutique associée.
        if ($user->store_id === null) {
            abort(403, 'Aucune boutique associée.');
        }

        $store = $user->store;

        if ($store === null) {
            abort(403, 'Boutique introuvable.');
        }

        // Une boutique inactive ne peut pas utiliser
        // les routes privées des commerçants.
        if ($store->status !== 'ACTIVE') {
            abort(403, 'Cette boutique est inactive.');
        }

        // Définir exclusivement la boutique
        // appartenant à l'utilisateur connecté.
        $this->tenantContext->setFromStore($store);

        // Conserver l'identifiant de diagnostic
        // utilisé par les vérifications existantes.
        $request->attributes->set(
            'tenant_context_object_id',
            spl_object_id($this->tenantContext)
        );

        return $next($request);
    }
}
