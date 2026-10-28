<?php

namespace App\Http\Middleware;

use App\Support\CatalogAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCatalogAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()->getName();
        $operation = match (true) {
            $request->routeIs('professional.promotions.index', 'professional.promotions.edit') => ['products.view', false],
            str_starts_with($name, 'professional.inventory.') => $request->routeIs('professional.inventory.receive', 'professional.inventory.adjust', 'professional.inventory.submit') ? ['inventory.manage', true] : ['inventory.view', false],
            str_starts_with($name, 'professional.categories.') => match (true) {
                $request->routeIs('professional.categories.index') => ['categories.view', false],
                $request->routeIs('professional.categories.create', 'professional.categories.store') => ['categories.create', true],
                $request->routeIs('professional.categories.destroy') => ['categories.delete', true],
                default => ['categories.update', true],
            },
            $request->routeIs('professional.products.index', 'professional.products.edit') => ['products.view', false],
            $request->routeIs('professional.products.create', 'professional.products.store') => ['products.create', true],
            default => ['products.update', true],
        };
        CatalogAccess::context($request, ...$operation);

        return $next($request);
    }
}
