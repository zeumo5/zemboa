<?php
namespace App\Http\Middleware;


use App\Models\Store;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPublicTenantContext
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        // Le slug provient de l'URL publique.
        $slug = $request->route('shopSlug');

        if (!is_string($slug) || $slug === '') {
            abort(404);
        }

        // Seules les boutiques actives sont publiques.
        $store = Store::query()
            ->where('slug', $slug)
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        // Établir le contexte de la boutique visitée.
        app(TenantContext::class)->setFromStore($store);

        return $next($request);
    }
}
