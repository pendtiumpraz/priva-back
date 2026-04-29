<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton: shared per request, holds the active tenant org_id.
        // Read by BelongsToOrg trait's global scope.
        $this->app->singleton(\App\Services\CurrentOrgContext::class);

        // Tenancy services — singletons because they hold per-request
        // connection caches that must be shared across the request.
        $this->app->singleton(\App\Services\TenantDb\TenantDatabaseService::class);
        $this->app->singleton(\App\Services\TenantDb\DatabasePoolRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 0. Register a `landlord` connection alias that mirrors the original
        //    default connection. Used by platform-level models (User,
        //    Organization, License, AppSetting, DatabasePool, StoragePool,
        //    TenantChangeRequest, MenuItem) so they keep hitting the landlord
        //    DB even after tenant.db middleware switches the default to a
        //    per-tenant connection. Without this, queries on those models
        //    would land in the wrong DB once a tenant goes isolated.
        $defaultName = config('database.default');
        if ($defaultName && config("database.connections.{$defaultName}")) {
            config(['database.connections.landlord' => config("database.connections.{$defaultName}")]);
        }

        // 1. Prevent N+1 Lazy Loading in Dev
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(! $this->app->isProduction());

        // 2. Global Rate Limiting for API
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 3. Register Socialite Providers
        \Illuminate\Support\Facades\Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
                $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
                $event->extendSocialite('keycloak', \SocialiteProviders\Keycloak\Provider::class);
            }
        );
    }
}
