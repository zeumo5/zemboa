<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class UseCartSessionLocks
{
    public function handle(Request $request, Closure $next): Response
    {
        // Every web request can save the complete session, including an old cart.
        // Configure Laravel's ONE native lock before StartSession reads anything.
        $store = app()->environment('demo') ? 'demo_limiter' : config('session.block_store', 'file');
        $driver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;
        abort_unless(in_array($driver, ['file', 'redis'], true), 503);
        abort_if(config('session.driver') === 'cookie', 503);
        abort_unless(is_int(config('session.block_lock_seconds')) && config('session.block_lock_seconds') > 0, 503);
        abort_unless(is_int(config('session.block_wait_seconds')) && config('session.block_wait_seconds') >= 0, 503);
        config(['session.block' => true, 'session.block_store' => $store]);

        return $next($request);
    }
}
