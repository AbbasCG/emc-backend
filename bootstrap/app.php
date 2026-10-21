<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Allow first-party browser clients to authenticate through Sanctum's
        // encrypted HttpOnly session cookie. Bearer-token clients remain
        // supported by auth:sanctum as a compatibility fallback.
        $middleware->statefulApi();

        $middleware->alias([
            'role'               => \App\Http\Middleware\RoleMiddleware::class,
            // Department-leadership route entry: a listed role OR the canonical
            // leader of an active department. Grants entry only; every controller
            // behind it still performs its own department-scoped check.
            'role.or.leader'     => \App\Http\Middleware\DepartmentLeaderOrRoleMiddleware::class,
            'permission'         => \App\Http\Middleware\PermissionMiddleware::class,
            'optional.sanctum'   => \App\Http\Middleware\OptionalSanctumAuth::class,
            'active'             => \App\Http\Middleware\EnsureUserIsActive::class,
            // Entry to the learner experience for ANY authenticated, active account (replaces role:student).
            'learner'            => \App\Http\Middleware\EnsureLearnerAccess::class,
            'placement.required' => \App\Http\Middleware\PlacementTestRequired::class,
        ]);

        // Run on every api/* request — detects impersonation tokens and annotates
        // the request with is_impersonating + impersonator_id attributes.
        // No-op for unauthenticated requests or normal tokens.
        $middleware->appendToGroup('api', \App\Http\Middleware\ImpersonationMiddleware::class);

        // Block suspended accounts on every authenticated request.
        // EnsureUserIsActive is a no-op when $request->user() is null,
        // so it is safe to append globally — unauthenticated public routes are unaffected.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureUserIsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // ── Sentry error reporting (optional) ─────────────────────────────
        // Active only when sentry/sentry-laravel is installed and SENTRY_LARAVEL_DSN is set.
        // Install: composer require sentry/sentry-laravel
        if (
            env('SENTRY_LARAVEL_DSN') &&
            class_exists(\Sentry\Laravel\Integration::class)
        ) {
            $exceptions->report(function (\Throwable $e) {
                \Sentry\Laravel\Integration::captureUnhandledException($e);
            });
        }


        // 429 — Rate limit exceeded
        $exceptions->render(function (
            \Illuminate\Http\Exceptions\ThrottleRequestsException $e,
            \Illuminate\Http\Request $request,
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down and try again.',
            ], 429);
        });

        // 401 — Unauthenticated (Sanctum token missing / expired)
        // Always return JSON — this is an API-only project; there is no login route.
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request,
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please provide a valid session or Bearer token.',
            ], 401);
        });

        // 401 — RouteNotFoundException thrown when Sanctum tries route('login') for
        // browser requests (no Accept: application/json). Only relevant for api/* routes
        // since web routes legitimately use named login redirects.
        $exceptions->render(function (
            \Symfony\Component\Routing\Exception\RouteNotFoundException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please provide a valid session or Bearer token.',
                ], 401);
            }
        });

        // 403 — Unauthorized action (policy / gate failure)
        $exceptions->render(function (
            \Illuminate\Auth\Access\AuthorizationException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                ], 403);
            }
        });

        // 404 — Model not found (route model binding fails)
        $exceptions->render(function (
            \Illuminate\Database\Eloquent\ModelNotFoundException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $model = class_basename($e->getModel());

                return response()->json([
                    'success' => false,
                    'message' => "{$model} not found.",
                ], 404);
            }
        });

        // 404 — Route not found
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'The requested endpoint does not exist.',
                ], 404);
            }
        });

        // 405 — Method not allowed
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'HTTP method not allowed for this endpoint.',
                ], 405);
            }
        });

        // 422 — Validation errors (standardized wrapper)
        $exceptions->render(function (
            \Illuminate\Validation\ValidationException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

    })->create();
