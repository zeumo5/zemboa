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
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $this->tenantContext->setFromUser($user);
       $request->attributes->set(
    'tenant_context_object_id',
    spl_object_id($this->tenantContext)
);

        return $next($request);
    }
}