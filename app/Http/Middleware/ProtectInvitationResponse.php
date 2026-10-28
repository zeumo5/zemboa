<?php

namespace App\Http\Middleware;

use App\Support\InvitationPrivacy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectInvitationResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return InvitationPrivacy::protect($next($request), $request);
    }
}
