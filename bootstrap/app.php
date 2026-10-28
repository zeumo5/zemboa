<?php

use App\Http\Middleware\EnsureBuyerAuthenticated;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPublicTenantContext;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\UseCartSessionLocks;
use App\Logging\RedactInvitationSecrets;
use App\Support\InvitationPrivacy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->web(prepend: [UseCartSessionLocks::class], append: [
            SetLocale::class,
        ]);

        $middleware->alias([
            'buyer' => EnsureBuyerAuthenticated::class,
            'tenant' => SetTenantContext::class,
            'public.tenant' => SetPublicTenantContext::class,
            'admin' => EnsureSuperAdmin::class,
        ]);

        $middleware->priority([
            SetTenantContext::class,
            SetPublicTenantContext::class,
            SubstituteBindings::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', '_token', 'password', 'password_confirmation', 'current_password']);
        $exceptions->report(function (Throwable $exception) {
            if (app()->bound('request') && InvitationPrivacy::applies(request())) {
                Log::error('Invitation request failed.', [
                    'exception' => app(RedactInvitationSecrets::class)->exceptionContext($exception),
                ]);

                return false;
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! InvitationPrivacy::applies($request)
                || $exception instanceof ValidationException) {
                return null;
            }
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode() : 500;
            $headers = $exception instanceof HttpExceptionInterface
                ? $exception->getHeaders() : [];
            $message = app()->getLocale() === 'en'
                ? 'The invitation could not be processed.' : 'Impossible de traiter cette invitation.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], $status, $headers)
                : response()->view('auth.invitation-unavailable', [], $status, $headers);
        });
        $exceptions->respond(fn (Response $response) => InvitationPrivacy::protect($response, request()));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
