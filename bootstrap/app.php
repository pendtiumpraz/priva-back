<?php

use App\Http\Middleware\AuthenticateConsentApiKey;
use App\Http\Middleware\AuthenticateDsrApiKey;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnforceTenantReadOnly;
use App\Http\Middleware\InitializeTenantDatabase;
use App\Http\Middleware\PublicAssessmentTokenMiddleware;
use App\Http\Middleware\RootOnly;
use App\Http\Middleware\RootOrSuperadmin;
use App\Http\Middleware\SanctumTokenRefresh;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentOrgContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => CheckPermission::class,
            'dsr.api_key' => AuthenticateDsrApiKey::class,
            'consent.api_key' => AuthenticateConsentApiKey::class,
            'role.root' => RootOrSuperadmin::class,
            'role.root_only' => RootOnly::class,
            'tenant.context' => SetCurrentOrgContext::class,
            'tenant.db' => InitializeTenantDatabase::class,
            'tenant.readonly' => EnforceTenantReadOnly::class,
            // Public TPRM assessment — resolve token, set tenant context, rate-limit.
            'public-assessment-token' => PublicAssessmentTokenMiddleware::class,
        ]);

        // Stamp security headers (HSTS, frame-options, referrer-policy, dst)
        // ke SEMUA response. Master toggle + per-header knob tersedia di
        // /platform-admin/system-settings → Security section.
        $middleware->append(SecurityHeaders::class);

        // Sliding refresh untuk Sanctum tokens — kalau token udah lewat
        // threshold % dari lifetime, issue token baru via header
        // X-Refreshed-Token. Append global aman karena no-op untuk request
        // tanpa user authenticated.
        $middleware->append(SanctumTokenRefresh::class);

        // Prevent "Route [login] not defined" on API auth failures
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
