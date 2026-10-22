<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBuyerAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('buyer');
        $user = $guard->user();
        if (! $user || ! $user->canUseBuyerAccount()) {
            if ($user) {
                $guard->logoutCurrentDevice();
                $request->session()->regenerate(true);
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('buyer.authentication_required')], 401)
                : redirect()->route('buyer.login');
        }

        return $next($request);
    }
}
