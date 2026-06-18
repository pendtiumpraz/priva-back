<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Lms\Services\MuxService;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Root-only platform-wide config read/write + queue health + AWS budget
 * estimator. See SETTINGS_SCHEMA for the editable surface and its min/max
 * bounds — frontend clamps the UI to the same bounds, backend re-validates.
 */
class PlatformConfigController extends Controller
{
    /**
     * Editable settings stored in app_settings (key-value). Each entry is
     * (key => [default, min, max, step, unit, category, label, help]).
     *
     * "soft" settings take effect immediately. "hard" env settings are
     * surfaced read-only in index() but never accepted in update().
     */
    public const SETTINGS_SCHEMA = [
        'consent.cache_ttl_seconds' => [
            'default' => 300, 'min' => 30, 'max' => 3600, 'step' => 30,
            'unit' => 's', 'category' => 'consent', 'label' => 'Consent Config Cache TTL',
            'help' => 'Berapa lama /public/consent/config di-cache. Semakin besar = throughput tinggi tapi perubahan banner lebih lambat terlihat.',
        ],
        'consent.collection_cache_ttl_seconds' => [
            'default' => 600, 'min' => 60, 'max' => 3600, 'step' => 60,
            'unit' => 's', 'category' => 'consent', 'label' => 'Collection Lookup Cache TTL',
            'help' => 'Cache untuk lookup collection di capture endpoint.',
        ],
        'consent.recount_interval_minutes' => [
            'default' => 5, 'min' => 1, 'max' => 60, 'step' => 1,
            'unit' => 'min', 'category' => 'consent', 'label' => 'Records Count Recount Interval',
            'help' => 'Cadence rebuild records_count via consent:recount. Counter advisory — nggak perlu real-time.',
        ],
        'queue.default_tries' => [
            'default' => 3, 'min' => 1, 'max' => 10, 'step' => 1,
            'unit' => 'x', 'category' => 'queue', 'label' => 'Queue Default Retries',
            'help' => 'Berapa kali job di-retry sebelum failed.',
        ],
        'queue.webhook_timeout_seconds' => [
            'default' => 5, 'min' => 2, 'max' => 60, 'step' => 1,
            'unit' => 's', 'category' => 'queue', 'label' => 'Webhook HTTP Timeout',
            'help' => 'Timeout HTTP call ke webhook tenant.',
        ],
        'ai.default_temperature' => [
            'default' => 0.3, 'min' => 0.0, 'max' => 2.0, 'step' => 0.1,
            'unit' => '', 'category' => 'ai', 'label' => 'AI Default Temperature',
            'help' => 'Default creativity knob untuk AI call. 0 = deterministik, 1 = kreatif.',
        ],
        'ai.default_max_tokens' => [
            'default' => 2000, 'min' => 256, 'max' => 8000, 'step' => 128,
            'unit' => 'tok', 'category' => 'ai', 'label' => 'AI Default Max Tokens',
            'help' => 'Max response tokens.',
        ],
        'ai.credits_low_threshold_percent' => [
            'default' => 10, 'min' => 1, 'max' => 50, 'step' => 1,
            'unit' => '%', 'category' => 'ai', 'label' => 'AI Credits Low-Alert Threshold',
            'help' => 'Alert admin tenant kalau credit sisa <= threshold ini.',
        ],
        'security.idle_timeout_default_minutes' => [
            'default' => 30, 'min' => 5, 'max' => 1440, 'step' => 5,
            'unit' => 'min', 'category' => 'security', 'label' => 'Default Idle Timeout',
            'help' => 'Default auto-logout duration untuk tenant baru. Tenant bisa override di Settings.',
        ],
        'features.ai_agent_enabled' => [
            'default' => 1, 'min' => 0, 'max' => 1, 'step' => 1,
            'unit' => 'bool', 'category' => 'features', 'label' => 'AI Agent Feature',
            'help' => 'Global kill switch untuk AI Agent chat.',
        ],
        'features.consent_webhooks_enabled' => [
            'default' => 1, 'min' => 0, 'max' => 1, 'step' => 1,
            'unit' => 'bool', 'category' => 'features', 'label' => 'Consent Webhooks',
            'help' => 'Aktifkan fire webhook ke tenant pas consent captured.',
        ],
        'features.notifications_enabled' => [
            'default' => 1, 'min' => 0, 'max' => 1, 'step' => 1,
            'unit' => 'bool', 'category' => 'features', 'label' => 'Notifikasi Sistem',
            'help' => 'Global kill switch untuk seluruh sistem notifikasi (bell, /notifications, scheduler, WA deep-link). Matikan kalau mau silent mode.',
        ],
        'features.notifications_scheduler_enabled' => [
            'default' => 1, 'min' => 0, 'max' => 1, 'step' => 1,
            'unit' => 'bool', 'category' => 'features', 'label' => 'Notifikasi Scheduler',
            'help' => 'Aktifkan daily cron untuk license-expiry, DSR H-24, RoPA review 90d, dll. Independen dari kill switch master.',
        ],
    ];

    /**
     * Mux (LMS video) credential fields — platform-global, root-managed.
     * Secret fields are stored encrypted and never returned (write-only).
     */
    public const MUX_FIELDS = [
        'token_id' => ['secret' => false, 'label' => 'Mux Token ID'],
        'token_secret' => ['secret' => true, 'label' => 'Mux Token Secret'],
        'signing_key_id' => ['secret' => false, 'label' => 'Signing Key ID'],
        'signing_key_private_key' => ['secret' => true, 'label' => 'Signing Key (base64 PEM)'],
        'default_playback_policy' => ['secret' => false, 'label' => 'Default Playback Policy'],
        'playback_token_ttl' => ['secret' => false, 'label' => 'Playback Token TTL (detik)'],
    ];

    public function index(Request $request)
    {
        $this->requireRoot($request);

        $dbSettings = AppSetting::whereIn('key', array_keys(self::SETTINGS_SCHEMA))
            ->pluck('value', 'key')->toArray();

        $editable = [];
        foreach (self::SETTINGS_SCHEMA as $key => $meta) {
            $editable[] = array_merge($meta, [
                'key' => $key,
                'value' => $dbSettings[$key] ?? $meta['default'],
            ]);
        }

        // Read-only env surface for visibility (DON'T expose secrets).
        $envReadOnly = [
            'APP_ENV' => env('APP_ENV'),
            'QUEUE_CONNECTION' => env('QUEUE_CONNECTION', 'sync'),
            'CACHE_STORE' => env('CACHE_STORE', 'file'),
            'DB_CONNECTION' => env('DB_CONNECTION'),
            'REDIS_HOST' => env('REDIS_HOST'),
            'FRONTEND_DEPLOY_MODE' => env('FRONTEND_DEPLOY_MODE', 'shell'),
            'FRONTEND_PATH' => env('FRONTEND_PATH'),
            'NPM_BIN' => env('NPM_BIN'),
        ];

        return response()->json([
            'editable' => $editable,
            'env_readonly' => $envReadOnly,
            'queue_health' => $this->queueHealth(),
        ]);
    }

    public function update(Request $request)
    {
        $this->requireRoot($request);

        $data = $request->validate([
            'key' => 'required|string|in:'.implode(',', array_keys(self::SETTINGS_SCHEMA)),
            'value' => 'required',
        ]);

        $meta = self::SETTINGS_SCHEMA[$data['key']];
        $value = is_numeric($data['value']) ? (float) $data['value'] : $data['value'];

        if (is_numeric($value)) {
            if ($value < $meta['min'] || $value > $meta['max']) {
                return response()->json([
                    'message' => "Nilai di luar batas ({$meta['min']}–{$meta['max']}).",
                ], 422);
            }
        }

        $before = AppSetting::get($data['key']);
        AppSetting::set($data['key'], (string) $value);

        try {
            AuditLog::log('platform_config', $data['key'], 'updated', [
                'key' => $data['key'], 'before' => $before, 'after' => $value,
            ], 'platform_config');
        } catch (\Throwable $e) {
            \Log::warning('audit failed: '.$e->getMessage());
        }

        return response()->json(['message' => 'Saved', 'key' => $data['key'], 'value' => $value]);
    }

    /**
     * Read Mux config status. Secrets are NEVER returned — only whether they are
     * set and from where (db override vs .env fallback). Non-secrets returned.
     */
    public function muxConfig(Request $request)
    {
        $this->requireRoot($request);

        $prefix = MuxService::PREFIX;
        $fields = [];
        foreach (self::MUX_FIELDS as $key => $meta) {
            $stored = AppSetting::get($prefix.$key);
            $envFallback = config('services.mux.'.$key);
            $source = filled($stored) ? 'db' : (filled($envFallback) ? 'env' : 'unset');
            if ($meta['secret']) {
                $fields[$key] = ['label' => $meta['label'], 'secret' => true, 'is_set' => $source !== 'unset', 'source' => $source];
            } else {
                $fields[$key] = ['label' => $meta['label'], 'secret' => false, 'value' => filled($stored) ? $stored : (string) ($envFallback ?? ''), 'source' => $source];
            }
        }

        $svc = new MuxService;

        return response()->json([
            'fields' => $fields,
            'configured' => $svc->configured(),
            'signing_configured' => $svc->signingConfigured(),
        ]);
    }

    /**
     * Save Mux config. Secret fields left blank are kept unchanged (write-only);
     * secrets are encrypted at rest. Empty .env fallback still applies for unset.
     */
    public function updateMux(Request $request)
    {
        $this->requireRoot($request);

        $payload = $request->validate([
            'token_id' => 'nullable|string|max:255',
            'token_secret' => 'nullable|string|max:8000',
            'signing_key_id' => 'nullable|string|max:255',
            'signing_key_private_key' => 'nullable|string|max:20000',
            'default_playback_policy' => 'nullable|in:signed,public',
            'playback_token_ttl' => 'nullable|integer|min:60|max:86400',
        ]);

        $prefix = MuxService::PREFIX;
        $changed = [];
        foreach (self::MUX_FIELDS as $key => $meta) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $val = $payload[$key];
            // Secret left blank → keep existing (don't wipe).
            if ($meta['secret'] && ($val === null || $val === '')) {
                continue;
            }
            if (! $meta['secret'] && $val === null) {
                continue;
            }
            AppSetting::set($prefix.$key, $meta['secret'] ? Crypt::encryptString((string) $val) : (string) $val);
            $changed[] = $key;
        }

        try {
            AuditLog::log('platform_config', 'lms.mux', 'updated', ['changed' => $changed], 'platform_config');
        } catch (\Throwable $e) {
            \Log::warning('audit failed: '.$e->getMessage());
        }

        return response()->json(['message' => 'Mux config disimpan.', 'changed' => $changed]);
    }

    public function queueHealth(): array
    {
        $driver = env('QUEUE_CONNECTION', 'sync');
        $out = ['driver' => $driver];
        try {
            if ($driver === 'database') {
                $out['pending'] = DB::table('jobs')->count();
                $out['failed'] = DB::table('failed_jobs')->count();
                $out['oldest_pending'] = DB::table('jobs')->min('available_at');
            } elseif ($driver === 'sync') {
                $out['pending'] = 0;
                $out['failed'] = 0;
                $out['warning'] = 'sync driver — job jalan inline, bukan async. Ganti ke database atau redis untuk scale.';
            } else {
                $out['info'] = "Driver {$driver} — health probe tidak diimplementasi untuk driver ini.";
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * AWS budget estimator. All pricing in USD/hour from AWS Jakarta region
     * (ap-southeast-3) list prices, late-2025. We convert to IDR at the
     * requested rate. Output: per-component cost in IDR per hour + per month
     * + per 1M hits. Explicitly flagged as estimate (±20%).
     */
    public function budget(Request $request)
    {
        $this->requireRoot($request);

        $req = $request->validate([
            'req_per_sec' => 'nullable|numeric|min:0|max:1000000',
            'web_instances' => 'nullable|integer|min:1|max:200',
            'worker_instances' => 'nullable|integer|min:0|max:200',
            'db_class' => 'nullable|string|in:small,medium,large,xlarge',
            'redis_class' => 'nullable|string|in:none,small,medium,large',
            'cdn_enabled' => 'nullable|boolean',
            'kinesis_shards' => 'nullable|integer|min:0|max:1000',
            'usd_to_idr' => 'nullable|numeric|min:10000|max:25000',
        ]);

        $reqPerSec = (float) ($req['req_per_sec'] ?? 100);
        $web = (int) ($req['web_instances'] ?? 2);
        $workers = (int) ($req['worker_instances'] ?? 1);
        $dbClass = $req['db_class'] ?? 'medium';
        $redisClass = $req['redis_class'] ?? 'small';
        $cdn = (bool) ($req['cdn_enabled'] ?? true);
        $shards = (int) ($req['kinesis_shards'] ?? 0);
        $fx = (float) ($req['usd_to_idr'] ?? 16200);

        // Per-component USD/hr — list prices AWS ap-southeast-3 (Jakarta).
        $WEB_USD_HR = 0.0832;   // t3.large
        $WORKER_USD_HR = 0.0832;
        $ALB_USD_HR = 0.0225 + (0.008 * max(1, ceil($reqPerSec / 25000))); // LCU grows with traffic

        $DB = [
            'small' => 0.210,  // db.r6g.large
            'medium' => 0.420,  // db.r6g.xlarge
            'large' => 0.840,  // db.r6g.2xlarge
            'xlarge' => 1.680,  // db.r6g.4xlarge
        ][$dbClass];

        $REDIS = [
            'none' => 0.0,
            'small' => 0.252,   // cache.r6g.large
            'medium' => 0.504,   // cache.r6g.xlarge
            'large' => 1.008,   // cache.r6g.2xlarge
        ][$redisClass];

        // CloudFront: 85% of traffic if CDN on; average 2KB per consent response.
        $cfGbPerHour = $cdn ? ($reqPerSec * 0.85 * 2048 * 3600 / 1_000_000_000) : 0;
        $CF_USD_HR = $cdn ? ($cfGbPerHour * 0.085) + (($reqPerSec * 0.85 * 3600 / 10_000_000) * 0.0075) : 0;
        // 15% of traffic still hits origin via ALB data transfer out (~$0.12/GB)
        $egressGb = $reqPerSec * ($cdn ? 0.15 : 1.0) * 2048 * 3600 / 1_000_000_000;
        $DT_USD_HR = $egressGb * 0.12;

        // Kinesis (only if explicit shards > 0 — implies async ingestion path).
        $KINESIS_USD_HR = $shards > 0
            ? ($shards * 0.0195) + (($reqPerSec * 3600 / 1_000_000) * 0.0195)
            : 0.0;

        $componentsUsd = [
            'ec2_web' => $web * $WEB_USD_HR,
            'ec2_workers' => $workers * $WORKER_USD_HR,
            'alb' => $ALB_USD_HR,
            'rds_postgres' => $DB,
            'elasticache' => $REDIS,
            'cloudfront' => $CF_USD_HR,
            'data_transfer' => $DT_USD_HR,
            'kinesis' => $KINESIS_USD_HR,
        ];
        $totalUsdHr = array_sum($componentsUsd);

        $components = [];
        foreach ($componentsUsd as $k => $usdHr) {
            $components[$k] = [
                'usd_per_hour' => round($usdHr, 4),
                'idr_per_hour' => round($usdHr * $fx),
                'idr_per_month' => round($usdHr * 730 * $fx),
            ];
        }

        $reqPerHour = $reqPerSec * 3600;
        $reqPerMonth = $reqPerSec * 730 * 3600;

        return response()->json([
            'inputs' => [
                'req_per_sec' => $reqPerSec,
                'web_instances' => $web,
                'worker_instances' => $workers,
                'db_class' => $dbClass,
                'redis_class' => $redisClass,
                'cdn_enabled' => $cdn,
                'kinesis_shards' => $shards,
                'usd_to_idr' => $fx,
            ],
            'totals' => [
                'usd_per_hour' => round($totalUsdHr, 4),
                'idr_per_hour' => round($totalUsdHr * $fx),
                'idr_per_month' => round($totalUsdHr * 730 * $fx),
                'idr_per_million_hits' => $reqPerHour > 0
                    ? round(($totalUsdHr / $reqPerHour) * 1_000_000 * $fx)
                    : 0,
            ],
            'components' => $components,
            'disclaimer' => 'Estimasi AWS ap-southeast-3 (Jakarta) list price. Realita dapat ±20% tergantung data egress, reserved instance discount, savings plan, dan tenant traffic mix. Belum termasuk S3, CloudWatch, SES, backup, NAT gateway.',
        ]);
    }

    // Toggle visibilitas tab Holding Dashboard (global, di-set superadmin di /settings).
    private const HOLDING_TAB_MAP = [
        'asesmen' => 'features.holding_tab_asesmen_enabled',
        'review' => 'features.holding_tab_review_enabled',
        'kepatuhan' => 'features.holding_tab_kepatuhan_enabled',
    ];

    /**
     * GET /holding/dashboard-tabs — boleh dibaca semua user terautentikasi
     * (Holding Dashboard butuh untuk filter tab). Default semua aktif (true).
     */
    public function holdingTabs(Request $request)
    {
        return response()->json(['data' => $this->readHoldingTabs()]);
    }

    /**
     * PUT /holding/dashboard-tabs — hanya superadmin / root.
     * Body: { asesmen?:bool, review?:bool, kepatuhan?:bool }
     */
    public function updateHoldingTabs(Request $request)
    {
        $role = $request->user()->role ?? null;
        if (! in_array($role, ['root', 'superadmin'], true)) {
            abort(403, 'Hanya superadmin / root yang dapat mengatur tab Holding Dashboard.');
        }

        $data = $request->validate([
            'asesmen' => 'sometimes|boolean',
            'review' => 'sometimes|boolean',
            'kepatuhan' => 'sometimes|boolean',
        ]);

        foreach (self::HOLDING_TAB_MAP as $field => $key) {
            if (array_key_exists($field, $data)) {
                AppSetting::set($key, $data[$field] ? '1' : '0');
            }
        }

        try {
            AuditLog::log('platform_config', 'holding_dashboard_tabs', 'updated', $data, 'platform_config');
        } catch (\Throwable $e) {
            \Log::warning('audit failed: '.$e->getMessage());
        }

        return response()->json(['message' => 'Saved', 'data' => $this->readHoldingTabs()]);
    }

    private function readHoldingTabs(): array
    {
        $out = [];
        foreach (self::HOLDING_TAB_MAP as $field => $key) {
            $out[$field] = (string) AppSetting::get($key, '1') !== '0';
        }

        return $out;
    }

    private function requireRoot(Request $request): void
    {
        if (($request->user()->role ?? null) !== 'root') {
            abort(403, 'Hanya role root.');
        }
    }
}
