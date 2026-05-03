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
