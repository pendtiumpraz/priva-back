<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Hydrates Laravel config from the `system_settings` table on boot, with a
 * file cache fallback so we don't query the DB on every request.
 *
 * Boot order (INFRASTRUCTURE_PLAN.md §3):
 *   1. Read .env (APP_KEY, DB_*).
 *   2. Database providers boot.
 *   3. *This provider* boots — populates Config from cache or DB before
 *      Redis/Queue/Cache/Session providers consume their config.
 *
 * Failure modes are graceful: missing table, unreachable DB, or unreachable
 * Redis all fall back to safe defaults (file/database drivers) without
 * crashing the boot. Errors get logged at warning level for the superadmin
 * settings UI to surface as a banner.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /**
     * File cache TTL — 5 minutes. Short enough that admin saves propagate
     * quickly across PHP-FPM workers, long enough to absorb traffic bursts.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function register(): void
    {
        // No bindings — provider is purely a boot-time config loader.
    }

    public function boot(): void
    {
        $settings = $this->loadSettings();

        if ($settings === null) {
            // Couldn't load — leave config defaults (env / config files) in
            // place. The admin UI will show a banner indicating that.
            return;
        }

        $this->applyToConfig($settings);
        $this->applyDriverFallbacks($settings);
    }

    /**
     * Load settings from file cache, or from DB if cache is stale/missing.
     * Returns a flat associative array `key => value` (decrypted).
     */
    private function loadSettings(): ?array
    {
        $cachePath = $this->cachePath();

        // 1. Try file cache.
        if (is_file($cachePath)) {
            $age = time() - filemtime($cachePath);
            if ($age < self::CACHE_TTL_SECONDS) {
                $raw = @file_get_contents($cachePath);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        // 2. Read from DB and rebuild cache.
        try {
            // Skip if migrations haven't run yet (fresh install / CI before migrate).
            if (! Schema::hasTable('system_settings')) {
                return [];
            }

            $rows = DB::table('system_settings')->get(['key', 'value', 'is_encrypted']);

            $flat = [];
            foreach ($rows as $row) {
                $decoded = json_decode($row->value ?? 'null', true);
                if ($row->is_encrypted && is_string($decoded) && $decoded !== '') {
                    try {
                        $decoded = Crypt::decryptString($decoded);
                    } catch (\Throwable $e) {
                        Log::warning("SettingsServiceProvider: failed to decrypt {$row->key}", [
                            'error' => $e->getMessage(),
                        ]);
                        $decoded = null;
                    }
                }
                $flat[$row->key] = $decoded;
            }

            $this->writeCache($flat);

            return $flat;
        } catch (\Throwable $e) {
            // DB unreachable during boot is recoverable — keep going with
            // .env / config defaults.
            Log::warning('SettingsServiceProvider: DB read failed, using defaults', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Map flat settings keys onto Laravel config paths. Only sets keys that
     * exist in $settings — absent keys keep config-file defaults.
     */
    private function applyToConfig(array $settings): void
    {
        $map = [
            // Redis connection (default) — feeds queue/cache/session redis stores.
            'redis.host' => 'database.redis.default.host',
            'redis.port' => 'database.redis.default.port',
            'redis.password' => 'database.redis.default.password',
            'redis.database' => 'database.redis.default.database',

            // Infrastructure drivers.
            'infrastructure.queue_driver' => 'queue.default',
            'infrastructure.cache_driver' => 'cache.default',
            'infrastructure.session_driver' => 'session.driver',

            // Mail SMTP transport.
            'mail.smtp_host' => 'mail.mailers.smtp.host',
            'mail.smtp_port' => 'mail.mailers.smtp.port',
            'mail.smtp_username' => 'mail.mailers.smtp.username',
            'mail.smtp_password' => 'mail.mailers.smtp.password',

            // Custom ai.* config (operational toggles only — provider creds
            // come from the AiProvider model, not from system_settings).
            'ai.jobs_enabled' => 'ai.jobs_enabled',
            'ai.local_llm_url' => 'ai.local_llm_url',
            'ai.max_concurrent_per_user' => 'ai.max_concurrent_per_user',
            'ai.history_retention_days' => 'ai.history_retention_days',
            'ai.use_visual_ocr_fallback' => 'ai.use_visual_ocr_fallback',
            'ai.visual_ocr_text_threshold' => 'ai.visual_ocr_text_threshold',
            'ai.visual_ocr_max_pages' => 'ai.visual_ocr_max_pages',

            // Deployment mode.
            'deployment.mode' => 'ai.deployment_mode',

            // Security — login lockout. DB key flat (security.lockout_*),
            // dimapping ke nested config path supaya app code pakai
            // config('security.login_lockout.tier1_attempts') yang readable.
            'security.lockout_enabled' => 'security.login_lockout.enabled',
            'security.lockout_tier1_attempts' => 'security.login_lockout.tier1_attempts',
            'security.lockout_tier1_seconds' => 'security.login_lockout.tier1_seconds',
            'security.lockout_tier2_attempts' => 'security.login_lockout.tier2_attempts',
            'security.lockout_tier2_seconds' => 'security.login_lockout.tier2_seconds',
            'security.lockout_tier3_attempts' => 'security.login_lockout.tier3_attempts',
            'security.lockout_tier3_seconds' => 'security.login_lockout.tier3_seconds',
            'security.lockout_window_minutes' => 'security.login_lockout.window_minutes',

            // Security — password policy. DB key flat (security.password_*)
            // → config('security.password.*'). Service baca via config().
            'security.password_min_length' => 'security.password.min_length',
            'security.password_require_uppercase' => 'security.password.require_uppercase',
            'security.password_require_lowercase' => 'security.password.require_lowercase',
            'security.password_require_digit' => 'security.password.require_digit',
            'security.password_require_symbol' => 'security.password.require_symbol',
            'security.password_block_common' => 'security.password.block_common',
            'security.password_block_email_match' => 'security.password.block_email_match',

            // Security — response headers. Dibaca oleh SecurityHeaders middleware.
            'security.headers_enabled' => 'security.headers.enabled',
            'security.headers_hsts_enabled' => 'security.headers.hsts_enabled',
            'security.headers_hsts_max_age' => 'security.headers.hsts_max_age',
            'security.headers_frame_options' => 'security.headers.frame_options',
            'security.headers_referrer_policy' => 'security.headers.referrer_policy',
            'security.headers_permissions_policy' => 'security.headers.permissions_policy',

            // Security — CORS. Hydrate cors.allowed_origins dst dari settings.
            // `cors.allowed_origins` adalah array — applyToConfig() akan set
            // selama valuenya bukan null/empty string. Array kosong tetap
            // di-set (artinya: tolak semua cross-origin).
            'security.cors_allowed_origins' => 'cors.allowed_origins',
            'security.cors_allow_credentials' => 'cors.supports_credentials',
            'security.cors_max_age_seconds' => 'cors.max_age',

            // Security — token expiry. Ke `sanctum.expiration` (Sanctum baca
            // ini langsung untuk hard-cut) + ke security.token.* untuk
            // SanctumTokenRefresh middleware.
            'security.token_lifetime_minutes' => 'sanctum.expiration',
            'security.token_refresh_threshold_pct' => 'security.token.refresh_threshold_pct',

            // Security — AI prompt size limits. Dibaca oleh App\Services\AiPromptGuard.
            'security.ai_max_prompt_chars' => 'security.ai.max_prompt_chars',
            'security.ai_max_message_chars' => 'security.ai.max_message_chars',
            'security.ai_max_attachment_chars' => 'security.ai.max_attachment_chars',

            // Security — 2FA TOTP. Dibaca oleh App\Services\TwoFactorAuthService
            // dan AuthController.
            'security.2fa_enabled' => 'security.2fa_enabled',
            'security.2fa_required_for_root' => 'security.2fa_required_for_root',
            'security.2fa_required_for_superadmin' => 'security.2fa_required_for_superadmin',
            'security.2fa_required_for_admin' => 'security.2fa_required_for_admin',
            'security.2fa_required_for_dpo' => 'security.2fa_required_for_dpo',

            // Security — email verification.
            'security.email_verification_required' => 'security.email_verification_required',
            'security.email_verification_grace_minutes' => 'security.email_verification_grace_minutes',

            // Security — webhook HMAC.
            'security.webhook_hmac_required' => 'security.webhook_hmac_required',
            'security.webhook_timestamp_tolerance_seconds' => 'security.webhook_timestamp_tolerance_seconds',
        ];

        foreach ($map as $settingKey => $configPath) {
            if (! array_key_exists($settingKey, $settings)) {
                continue;
            }
            $value = $settings[$settingKey];
            if ($value === null || $value === '') {
                continue;
            }
            Config::set($configPath, $value);
        }

        // Defense in depth — kalau DB origins kosong/array kosong (mis. admin
        // sengaja hapus semua, atau fresh install belum migrate), fallback ke
        // env CORS_ALLOWED_ORIGINS supaya frontend production tetap bisa
        // connect saat first-deploy. Setelah admin set lewat UI, DB jadi
        // source of truth.
        $dbOrigins = $settings['security.cors_allowed_origins'] ?? null;
        if (! is_array($dbOrigins) || count($dbOrigins) === 0) {
            $envRaw = (string) env('CORS_ALLOWED_ORIGINS', '');
            if ($envRaw !== '') {
                $envOrigins = array_values(array_unique(array_filter(
                    array_map('trim', explode(',', $envRaw)),
                    fn ($s) => $s !== ''
                )));
                if (! empty($envOrigins)) {
                    Config::set('cors.allowed_origins', $envOrigins);
                }
            }
        }

        // Conditional SQS wiring — only when queue_driver=sqs. Object storage
        // (S3) is NOT wired here; that's handled by the StoragePool model on
        // a per-tenant basis.
        if (($settings['infrastructure.queue_driver'] ?? null) === 'sqs') {
            $sqsKey = $settings['infrastructure.sqs_access_key'] ?? null;
            $sqsSecret = $settings['infrastructure.sqs_secret_key'] ?? null;
            $sqsRegion = $settings['infrastructure.sqs_region'] ?? null;
            $sqsQueueUrl = $settings['infrastructure.sqs_queue_url'] ?? null;

            if ($sqsKey !== null && $sqsKey !== '') {
                Config::set('queue.connections.sqs.key', $sqsKey);
            }
            if ($sqsSecret !== null && $sqsSecret !== '') {
                Config::set('queue.connections.sqs.secret', $sqsSecret);
            }
            if ($sqsRegion !== null && $sqsRegion !== '') {
                Config::set('queue.connections.sqs.region', $sqsRegion);
            }
            if ($sqsQueueUrl !== null && $sqsQueueUrl !== '') {
                Config::set('queue.connections.sqs.queue', $sqsQueueUrl);
                // SQS prefix is everything up to the queue name segment.
                $prefix = preg_replace('#/[^/]+$#', '', $sqsQueueUrl);
                if ($prefix && $prefix !== $sqsQueueUrl) {
                    Config::set('queue.connections.sqs.prefix', $prefix);
                }
            }
        }
    }

    /**
     * Graceful fallback: if hosting tier or queue/cache/session is set to
     * `redis` but the connection details look unreachable, downgrade to
     * file/database to keep the app booting.
     *
     * We don't actually probe Redis here (would slow every request) — the
     * settings UI's Test Connection button does that. This method just
     * applies the `degraded mode` flag set by the admin or by a previous
     * health check.
     */
    private function applyDriverFallbacks(array $settings): void
    {
        $degraded = (bool) ($settings['infrastructure.degraded_mode'] ?? false);
        if (! $degraded) {
            return;
        }

        Config::set('cache.default', 'file');
        Config::set('queue.default', 'database');
        Config::set('session.driver', 'database');
    }

    private function writeCache(array $settings): void
    {
        $path = $this->cachePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }

        // Atomic write via temp file + rename so concurrent reads never see
        // a half-written file.
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }

    /**
     * Path to the file cache. `bootstrap/cache/system_settings.json` is the
     * canonical location — same dir Laravel uses for routes.php cache so
     * it's already writable in any sane deploy.
     */
    private function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/system_settings.json');
    }

    /**
     * Public helper for the SystemSettingsController to invalidate the
     * cache after an admin save. Static so it works from anywhere.
     */
    public static function clearCache(): void
    {
        $path = app()->bootstrapPath('cache/system_settings.json');
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
