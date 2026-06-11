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

        // TPRM Phase 3 — Vendor screening search provider abstraction.
        // Hanya DuckDuckGo yang diimplementasi (free, no API key required).
        // Kalau nanti perlu provider lain, tambah impl SearchProviderInterface
        // + ganti binding di sini.
        $this->app->bind(
            \App\Services\VendorScreening\SearchProviderInterface::class,
            \App\Services\VendorScreening\DuckDuckGoHtmlSearchProvider::class,
        );

        // RAG services — singleton karena holds HTTP client + cache state
        $this->app->singleton(\App\Services\EmbeddingService::class);
        $this->app->singleton(\App\Services\VectorSearchService::class);
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

        // 2. Global Rate Limiting for API — default 240/menit (configurable
        // via security.api_rate_limit_per_minute). 60/menit lama terlalu ketat
        // untuk SPA yang fire belasan request per page-load: beberapa reload
        // beruntun → 429 → datatable tampil kosong.
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            $perMinute = (int) config('security.api_rate_limit_per_minute', 240);

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        // 2a. Per-tenant rate limit — layer kedua di atas 'api'. Mencegah
        // satu tenant flood seluruh platform (impact tenant lain). Bucket key
        // pakai org_id supaya semua user dari org yang sama share quota.
        // Kalau request gak punya org (root/superadmin lewat tanpa tenant
        // context), fall back ke user_id supaya tetap di-limit.
        //
        // Limit + master enabled configurable via security.tenant_rate_limit_*.
        // Default 300/menit cukup untuk dashboard yang load 10+ widget per
        // user di tenant dengan 30+ user aktif simultan.
        // 2b. Public TPRM assessment — 30 RPM per token. Bucket key dari
        // segment route `token` supaya rate-limit terikat ke link share,
        // bukan per-IP (banyak pihak ketiga di belakang NAT/proxy). Middleware
        // PublicAssessmentTokenMiddleware juga punya guard sendiri pakai
        // RateLimiter::tooManyAttempts — definisi di sini berguna kalau ada
        // route yang ingin pakai sintaks `throttle:public-assessment-token`.
        \Illuminate\Support\Facades\RateLimiter::for('public-assessment-token', function (\Illuminate\Http\Request $request) {
            $token = (string) ($request->route('token') ?? $request->ip());
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('public-asmt:' . sha1($token));
        });

        \Illuminate\Support\Facades\RateLimiter::for('tenant-api', function (\Illuminate\Http\Request $request) {
            $enabled = (bool) config('security.tenant_rate_limit_enabled', true);
            if (! $enabled) {
                return \Illuminate\Cache\RateLimiting\Limit::none();
            }

            $perMinute = (int) config('security.tenant_rate_limit_per_minute', 300);
            $user = $request->user();
            $bucket = $user?->org_id ?? $user?->id ?? $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)->by('tenant:'.$bucket);
        });

        // 3. Register Socialite Providers
        \Illuminate\Support\Facades\Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
                $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
                $event->extendSocialite('keycloak', \SocialiteProviders\Keycloak\Provider::class);
            }
        );

        // 4. Password reset link → frontend URL. Default Laravel pakai
        // route('password.reset') yang generate URL backend; user-facing
        // link harus ke FE page yang punya form input password baru.
        // FE route: /reset-password?token=...&email=...
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
            return $frontend.'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset());
        });

        // 5. RAG (Retrieval-Augmented Generation) observers.
        //    Auto-embed RoPA/DPIA/Breach/Vendor/KB ke vector_embeddings saat
        //    create/update. Skip kalau ai_embedding.enabled=false (default).
        //    Implementation di app/Observers/*EmbeddingObserver.php
        if (config('ai_embedding.enabled')) {
            try {
                \App\Models\Ropa::observe(\App\Observers\RopaEmbeddingObserver::class);
                \App\Models\Dpia::observe(\App\Observers\DpiaEmbeddingObserver::class);
                if (class_exists(\App\Models\BreachIncident::class)) {
                    \App\Models\BreachIncident::observe(\App\Observers\BreachEmbeddingObserver::class);
                }
                if (class_exists(\App\Models\VendorAssessment::class) && class_exists(\App\Observers\VendorEmbeddingObserver::class)) {
                    \App\Models\VendorAssessment::observe(\App\Observers\VendorEmbeddingObserver::class);
                }
                if (class_exists(\App\Models\KnowledgeBaseSection::class) && class_exists(\App\Observers\KbEmbeddingObserver::class)) {
                    \App\Models\KnowledgeBaseSection::observe(\App\Observers\KbEmbeddingObserver::class);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed registering embedding observers: ' . $e->getMessage());
            }
        }
    }
}
