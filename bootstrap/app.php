<?php

use App\Http\Middleware\AuthenticateConsentApiKey;
use App\Http\Middleware\AuthenticateDsrApiKey;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureLmsEntitled;
use App\Http\Middleware\EnforceTenantReadOnly;
use App\Http\Middleware\InitializeTenantDatabase;
use App\Http\Middleware\RootOrSuperadmin;
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
            'tenant.context' => SetCurrentOrgContext::class,
            'tenant.db' => InitializeTenantDatabase::class,
            'tenant.readonly' => EnforceTenantReadOnly::class,
            'lms.entitled' => EnsureLmsEntitled::class,
        ]);
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
