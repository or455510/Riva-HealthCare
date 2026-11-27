<?php

use App\Http\Middleware\EnsureClinicContext;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/signin');

        // COST / SECURITY (RIVA-101): API-wide rate limiting — see
        // AppServiceProvider::registerRateLimiters() for the named limiters
        // this applies ('api', plus 'ai-assistant'/'auth-sensitive'/
        // 'public-write' on specific routes in routes/api.php).
        $middleware->throttleApi();

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'role' => RoleMiddleware::class,
            // PHASE 1 (Identity + Tenancy): resolves and verifies the active clinic
            // membership for clinic-scoped routes. See EnsureClinicContext /
            // ClinicContextService.
            'clinic.context' => EnsureClinicContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });

        /**
         * SECURITY: previously, any uncaught exception on an API/JSON
         * request — most visibly a QueryException, e.g. the local MariaDB
         * "Host 'localhost' is not allowed to connect" access-control error
         * — was rendered straight to the client whenever APP_DEBUG=true
         * (the shipped .env.example default), including the raw SQL query,
         * bound parameters (which can include emails/passwords-in-transit
         * for auth endpoints), file paths, and a full stack trace. This was
         * visibly reaching real end users in the Angular UI (a raw SQLSTATE
         * message rendered on the Sign In screen), not just developers.
         *
         * This handler keeps full detail in the server log (Log::error,
         * same as every other error-handling path in this codebase) but
         * always returns a generic, safe message to API/JSON clients —
         * regardless of APP_DEBUG. It deliberately does NOT intercept the
         * exception types Laravel already renders safely and intentionally:
         * validation errors (422, real field messages the user needs),
         * HTTP-status exceptions (401/403/404/409/429/etc., including the
         * AuthenticationException case above and Laravel's own
         * AuthorizationException/HttpResponseException), so existing
         * behavior for those is unchanged. Non-JSON (Blade/browser) requests
         * are left alone (return null) so local browser-based debugging via
         * Laravel's own debug page is unaffected.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface
                || $e instanceof HttpResponseException
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException) {
                return null;
            }

            Log::error('Unhandled exception on API/JSON request', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong on our end. Please try again shortly.',
            ], 500);
        });
    })->create();
