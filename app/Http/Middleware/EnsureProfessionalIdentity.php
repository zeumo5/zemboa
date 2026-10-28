<?php

namespace App\Http\Middleware;

use App\Support\AccountDestination;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfessionalIdentity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AccountDestination::professionalIdentity($request->user('web')), 403);

        return $next($request);
    }
}
