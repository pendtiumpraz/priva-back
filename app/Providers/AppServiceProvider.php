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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
