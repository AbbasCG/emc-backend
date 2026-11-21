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

        // Public guest intake forms have no CSRF cookie to present; see App\Support\PublicIntakePaths for why that is safe and
        // how the list is kept honest.
        $middleware->validateCsrfTokens(except: \App\Support\PublicIntakePaths::CSRF_EXEMPT);

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
            // Write endpoints of the cancelled volunteer HR-profile pipeline: 410 unless volunteer_onboarding.hr_profile_pipeline_enabled.
            'volunteer.hr_pipeline' => \App\Http\Middleware\EnsureVolunteerHrPipelineEnabled::class,
            // Temporary-password gate. It is appended to the whole api group below; the alias exists for tests / explicit use.
            'password.changed'   => \App\Http\Middleware\EnsurePasswordIsChanged::class,
            // Sanctum token scopes (ApiTokenController::ALLOWED_SCOPES). `abilities:a,b` needs every listed ability, `ability:a,b` any
            // one. A first-party login token (abilities ["*"]) and a cookie session resolved by the sanctum guard (TransientToken)
            // pass every check; an impersonation bearer token (["via-impersonation"]) fails every check (403); a request with no
            // Sanctum token at all (guest, web-guard actingAs) is answered 401. Not attached to any route yet — see tests/Feature/ApiTokenAbilitiesTest.php.
            'abilities'          => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability'            => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            // Opt-in for scoped API tokens: `token.scope:read:courses` lets a scoped token holding EVERY listed scope through.
            // Without it a scoped token is refused everywhere (EnforceScopedTokenAccess, appended to the api group below).
            // Use this, not raw `abilities:`, on routes the SPA also calls: `abilities:` answers 403 to impersonation tokens.
            'token.scope'        => \App\Http\Middleware\EnforceScopedTokenAccess::class,
            // Token management / impersonation start: the real user's own SPA session or "*" login token only.
            'credential.first_party' => \App\Http\Middleware\RequireFirstPartyCredential::class,
        ]);

        // Authorization BEFORE route-model binding. SubstituteBindings (in the api group) turns {param} into a record and answers 404 when
        // none exists; if the role/permission gates run after it, an account outside the role list gets 404 for a missing id and 403 for
        // an existing one and can enumerate which records exist (manual payments, reconciliation cases, orders...). Putting the gates
        // ahead of it makes the answer 403 either way (tests/Feature/AuthorizationBeforeBindingTest.php sweeps every role-protected route).
        foreach ([
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\DepartmentLeaderOrRoleMiddleware::class,
            \App\Http\Middleware\PermissionMiddleware::class,
            \App\Http\Middleware\EnsureLearnerAccess::class,
            \App\Http\Middleware\EnforceScopedTokenAccess::class,
            \App\Http\Middleware\RequireFirstPartyCredential::class,
        ] as $gate) {
            $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: $gate);
        }

        // The gates above sort ahead of SubstituteBindings, which would also put them ahead of ImpersonationMiddleware. It must
        // see the request first: it annotates impersonation and stops the request that crosses a cookie preview's expiry.
        $middleware->prependToPriorityList(before: \App\Http\Middleware\RoleMiddleware::class, prepend: \App\Http\Middleware\ImpersonationMiddleware::class);

        // Run on every api/* request — detects impersonation tokens and annotates
        // the request with is_impersonating + impersonator_id attributes.
        // No-op for unauthenticated requests or normal tokens.
        $middleware->appendToGroup('api', \App\Http\Middleware\ImpersonationMiddleware::class);

        // Block suspended accounts on every authenticated request.
        // EnsureUserIsActive is a no-op when $request->user() is null,
        // so it is safe to append globally — unauthenticated public routes are unaffected.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureUserIsActive::class);

        // Fail-closed for scoped API tokens: refused on every api route that does not opt in with `token.scope:`.
        // SPA sessions, "*" login tokens, impersonation tokens and guests pass untouched (see the class).
        $middleware->appendToGroup('api', \App\Http\Middleware\EnforceScopedTokenAccess::class);

        // Force a temporary password to be replaced before anything else is reachable. One place, every authenticated api route;
        // must come after ImpersonationMiddleware (it skips impersonation sessions) and EnsureUserIsActive.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsurePasswordIsChanged::class);
        // ... and BEFORE route-model binding, like the other gates: a flagged account must not learn which record ids exist (404 vs 403).
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: \App\Http\Middleware\EnsurePasswordIsChanged::class);
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
            // Assignment uploads / submission downloads are student-facing and read by people, not machines: Arabic.
            if ($request->is('api/student/assignments/*/submit', 'api/submissions/*/download', 'api/submissions/*/files/*/download', 'api/instructor/submissions/*/file')) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return response()->json([
                    'success'     => false,
                    'code'        => 'too_many_requests',
                    'message'     => 'عدد الطلبات كبير. انتظر قليلاً ثم أعد المحاولة بعد '.$retryAfter.' ثانية.',
                    'retry_after' => $retryAfter,
                ], 429, $e->getHeaders());
            }

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down and try again.',
            ], 429);
        });

        // 413 — assignment submission larger than PHP's post_max_size. PHP discards the whole body in that case, and
        // Laravel's global ValidatePostSize middleware raises this exception first. Answer with the real reason and the
        // limits (JSON, Arabic) instead of a bare 413; every other route keeps Laravel's default handling.
        $exceptions->render(function (
            \Illuminate\Http\Exceptions\PostTooLargeException $e,
            \Illuminate\Http\Request $request,
        ) {
            if ($request->is('api/student/assignments/*/submit')) {
                return app(\App\Services\AssignmentUploads\OversizedUploadResponder::class)
                    ->make($request, 'server_post_max_size');
            }
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
