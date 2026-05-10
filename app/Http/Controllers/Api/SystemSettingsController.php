<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemSettings\AiRequest;
use App\Http\Requests\SystemSettings\DeploymentRequest;
use App\Http\Requests\SystemSettings\InfrastructureRequest;
use App\Http\Requests\SystemSettings\MailRequest;
use App\Http\Requests\SystemSettings\RedisRequest;
use App\Http\Requests\SystemSettings\SecurityRequest;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Providers\SettingsServiceProvider;
use Aws\Sqs\SqsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Mailer\Transport;

/**
 * Manage platform-wide system_settings (INFRASTRUCTURE_PLAN.md §4).
 *
 * Section-based UX: each section (infrastructure, redis, ai, mail,
 * deployment) has its own validator + save endpoint. Sensitive fields are
 * encrypted at rest via Crypt::encryptString and masked as "***" in reads.
 *
 * Out of scope: AI provider credentials live in the `ai_providers` table
 * (managed at /settings/ai-providers); object storage credentials live in
 * the `storage_pools` table (managed at /platform-admin/storage-pools).
 * SQS credentials sit on the infrastructure section because they're only
 * meaningful when queue_driver=sqs.
 *
 * Cache invalidation: every update wipes the
 * bootstrap/cache/system_settings.json file so the next request re-reads
 * from DB. Production deploys with multiple PHP-FPM workers should consider
 * triggering `php artisan queue:restart` after critical sections change
 * (frontend can issue this — see fase 9 of plan).
 */
class SystemSettingsController extends Controller
{
    /** Keys whose value column is Crypt-encrypted at rest. */
    private const ENCRYPTED_KEYS = [
        'redis.password',
        'mail.smtp_password',
        'infrastructure.sqs_access_key',
        'infrastructure.sqs_secret_key',
    ];

    /** Section → list of setting keys (single source of truth for save/read). */
    private const SECTION_KEYS = [
        'infrastructure' => [
            'infrastructure.hosting_tier',
            'infrastructure.queue_driver',
            'infrastructure.cache_driver',
            'infrastructure.session_driver',
            'infrastructure.sqs_access_key',
            'infrastructure.sqs_secret_key',
            'infrastructure.sqs_region',
            'infrastructure.sqs_queue_url',
        ],
        'redis' => [
            'redis.host',
            'redis.port',
            'redis.password',
            'redis.database',
        ],
        'ai' => [
            'ai.jobs_enabled',
            'ai.local_llm_url',
            'ai.max_concurrent_per_user',
            'ai.history_retention_days',
        ],
        'mail' => [
            'mail.smtp_host',
            'mail.smtp_port',
            'mail.smtp_username',
            'mail.smtp_password',
        ],
        'deployment' => [
            'deployment.mode',
        ],
        'security' => [
            // Login lockout
            'security.lockout_enabled',
            'security.lockout_tier1_attempts',
            'security.lockout_tier1_seconds',
            'security.lockout_tier2_attempts',
            'security.lockout_tier2_seconds',
            'security.lockout_tier3_attempts',
            'security.lockout_tier3_seconds',
            'security.lockout_window_minutes',
            // Password policy
            'security.password_min_length',
            'security.password_require_uppercase',
            'security.password_require_lowercase',
            'security.password_require_digit',
            'security.password_require_symbol',
            'security.password_block_common',
            'security.password_block_email_match',
            // Response headers
            'security.headers_enabled',
            'security.headers_hsts_enabled',
            'security.headers_hsts_max_age',
            'security.headers_frame_options',
            'security.headers_referrer_policy',
            'security.headers_permissions_policy',
        ],
    ];

    /**
     * Return all settings grouped by section. Sensitive values are replaced
     * with "***" — UI shows them as already-set without revealing plaintext.
     */
    public function index(): JsonResponse
    {
        $rows = SystemSetting::all();
        $grouped = [];

        foreach ($rows as $row) {
            $value = $row->is_encrypted
                ? ($this->hasEncryptedValue($row) ? '***' : null)
                : $row->value;

            $grouped[$row->section][$this->shortKey($row->key)] = $value;
        }

        // Always return all defined sections, even if empty.
        foreach (array_keys(self::SECTION_KEYS) as $section) {
            $grouped[$section] = $grouped[$section] ?? [];
        }

        return response()->json($grouped);
    }

    /**
     * Health summary per section. The settings UI uses this for the badge
     * next to each section header (configured | incomplete | not_configured).
     *
     * `test_failed` is NOT computed here (would require live probes on every
     * request); the frontend's Test Connection button writes results to
     * `<section>.last_test_*` keys for that.
     */
    public function health(): JsonResponse
    {
        $byKey = SystemSetting::all()->keyBy('key');
        $status = [];

        foreach (self::SECTION_KEYS as $section => $keys) {
            $required = $this->requiredKeysFor($section);
            $missing = [];
            $configured = 0;

            foreach ($keys as $key) {
                $hasValue = isset($byKey[$key]) && $this->isEffectivelySet($byKey[$key]);
                if ($hasValue) {
                    $configured++;
                } elseif (in_array($key, $required, true)) {
                    $missing[] = $this->shortKey($key);
                }
            }

            $status[$section] = [
                'status' => match (true) {
                    !empty($missing) && $configured === 0 => 'not_configured',
                    !empty($missing) => 'incomplete',
                    default => 'configured',
                },
                'missing' => $missing,
            ];
        }

        return response()->json($status);
    }

    /**
     * Save one section. Validates with the per-section FormRequest, encrypts
     * sensitive fields, upserts in a transaction, clears file cache, audits.
     *
     * Returns 200 + the same masked structure index() returns.
     */
    public function update(string $section, Request $request): JsonResponse
    {
        $validator = $this->validatorFor($section, $request);
        if ($validator === null) {
            return response()->json(['error' => 'Unknown section'], 404);
        }

        $validated = $validator->validated();
        $userId = $request->user()->id;
        $diff = [];

        DB::transaction(function () use ($section, $validated, $userId, &$diff) {
            foreach (self::SECTION_KEYS[$section] as $fullKey) {
                $shortKey = $this->shortKey($fullKey);

                // Skip keys not present in the validated payload — partial
                // section saves are allowed (e.g. only updating redis.host).
                if (! array_key_exists($shortKey, $validated)) {
                    continue;
                }

                $value = $validated[$shortKey];
                $isEncrypted = in_array($fullKey, self::ENCRYPTED_KEYS, true);

                // For sensitive fields, treat empty/"***" as "leave unchanged"
                // — admin re-saving a section without retyping the password
                // shouldn't wipe it.
                if ($isEncrypted && ($value === null || $value === '' || $value === '***')) {
                    $existing = SystemSetting::find($fullKey);
                    if ($existing && $this->hasEncryptedValue($existing)) {
                        continue;
                    }
                    // No prior value → store explicit null.
                    $stored = null;
                } elseif ($isEncrypted) {
                    $stored = Crypt::encryptString((string) $value);
                } else {
                    $stored = $value;
                }

                SystemSetting::updateOrCreate(
                    ['key' => $fullKey],
                    [
                        'value' => $stored,
                        'is_encrypted' => $isEncrypted,
                        'section' => $section,
                        'updated_by' => $userId,
                    ]
                );

                $diff[$shortKey] = $isEncrypted ? '***' : $value;
            }
        });

        SettingsServiceProvider::clearCache();

        $this->audit($section, $diff, $request);

        return response()->json([
            'ok' => true,
            'section' => $section,
            'changed' => array_keys($diff),
        ]);
    }

    /**
     * Test connection for a section. Does NOT persist — admin first tests,
     * then saves. Returns {ok, latency_ms, error?}.
     */
    public function test(string $section, Request $request): JsonResponse
    {
        $started = microtime(true);
        try {
            $result = match ($section) {
                'redis' => $this->testRedis($request),
                'mail' => $this->testMail($request),
                'infrastructure' => $this->testInfrastructure($request),
                default => throw new \InvalidArgumentException("Section {$section} has no test"),
            };

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            return response()->json(array_merge(
                ['ok' => true, 'latency_ms' => $latencyMs],
                $result,
            ));
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            return response()->json([
                'ok' => false,
                'latency_ms' => $latencyMs,
                'error' => $e->getMessage(),
            ], 200); // 200 because the test ran; payload reports failure
        }
    }

    // ──────────────────────────── connection tests ────────────────────────────

    private function testRedis(Request $request): array
    {
        $host = $request->input('host', config('database.redis.default.host'));
        $port = (int) $request->input('port', config('database.redis.default.port', 6379));
        $password = $request->input('password');
        $database = (int) $request->input('database', 0);

        // Build a one-off connection so we don't mutate the live connection
        // pool with potentially-bad credentials.
        config(['database.redis.test_probe' => [
            'host' => $host,
            'port' => $port,
            'password' => $password ?: null,
            'database' => $database,
            'client' => config('database.redis.client', 'phpredis'),
        ]]);

        $pong = Redis::connection('test_probe')->ping();
        return ['pong' => (string) $pong];
    }

    private function testMail(Request $request): array
    {
        $host = $request->input('smtp_host');
        $port = (int) $request->input('smtp_port', 587);
        $username = $request->input('smtp_username');
        $password = $request->input('smtp_password');

        if (! $host) {
            throw new \InvalidArgumentException('smtp_host required');
        }

        // Build a Symfony Mailer SMTP transport DSN and start it — that
        // performs an EHLO + AUTH handshake without sending.
        $auth = $username
            ? rawurlencode($username).':'.rawurlencode((string) $password).'@'
            : '';
        $dsn = "smtp://{$auth}{$host}:{$port}";

        $transport = Transport::fromDsn($dsn);
        $transport->start();
        $transport->stop();

        return ['handshake' => 'ok'];
    }

    /**
     * Test the SQS half of the infrastructure section. Only meaningful when
     * queue_driver=sqs; for any other driver this returns a no-op success.
     *
     * Uses GetQueueAttributes which is cheaper than sending a probe message
     * and validates both credentials and the queue URL in one call.
     */
    private function testInfrastructure(Request $request): array
    {
        if ($request->input('queue_driver') !== 'sqs') {
            return ['driver' => $request->input('queue_driver'), 'note' => 'no probe needed'];
        }

        if (! class_exists(SqsClient::class)) {
            throw new \RuntimeException('AWS SDK not installed (composer require aws/aws-sdk-php)');
        }

        $queueUrl = (string) $request->input('sqs_queue_url');
        if (! $queueUrl) {
            throw new \InvalidArgumentException('sqs_queue_url required');
        }

        $sqs = new SqsClient([
            'version' => 'latest',
            'region' => $request->input('sqs_region', 'ap-southeast-1'),
            'credentials' => [
                'key' => (string) $request->input('sqs_access_key'),
                'secret' => (string) $request->input('sqs_secret_key'),
            ],
        ]);

        $attrs = $sqs->getQueueAttributes([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        return [
            'queue_url' => $queueUrl,
            'approx_messages' => $attrs['Attributes']['ApproximateNumberOfMessages'] ?? null,
        ];
    }

    // ──────────────────────────── helpers ────────────────────────────

    private function validatorFor(string $section, Request $request)
    {
        $class = match ($section) {
            'infrastructure' => InfrastructureRequest::class,
            'redis' => RedisRequest::class,
            'ai' => AiRequest::class,
            'mail' => MailRequest::class,
            'deployment' => DeploymentRequest::class,
            'security' => SecurityRequest::class,
            default => null,
        };

        if (! $class) {
            return null;
        }

        // Build the FormRequest manually (we can't typehint a dynamic class
        // in the route signature). createFromBase + setContainer mirrors
        // what Laravel's resolver does internally.
        $formRequest = $class::createFrom($request);
        $formRequest->setContainer(app())->setRedirector(app('redirect'));
        $formRequest->validateResolved();

        return $formRequest;
    }

    /**
     * Strip the section prefix from a fully-qualified key.
     *   "redis.host" → "host"
     *   "infrastructure.sqs_access_key" → "sqs_access_key"
     */
    private function shortKey(string $fullKey): string
    {
        $pos = strpos($fullKey, '.');
        return $pos === false ? $fullKey : substr($fullKey, $pos + 1);
    }

    /**
     * Required keys per section for the health check. Optional sections
     * (mail, aws) intentionally have no required fields — they're allowed
     * to be empty.
     */
    private function requiredKeysFor(string $section): array
    {
        return match ($section) {
            // Only the four core driver fields are required; sqs_* are
            // conditionally required (handled by InfrastructureRequest).
            'infrastructure' => [
                'infrastructure.hosting_tier',
                'infrastructure.queue_driver',
                'infrastructure.cache_driver',
                'infrastructure.session_driver',
            ],
            'redis' => ['redis.host', 'redis.port', 'redis.database'],
            // AI provider creds are managed via ai_providers table; this
            // section's only required toggles are the operational ones.
            'ai' => ['ai.jobs_enabled', 'ai.max_concurrent_per_user', 'ai.history_retention_days'],
            'deployment' => ['deployment.mode'],
            'security' => [
                'security.lockout_enabled',
                'security.lockout_tier1_attempts',
                'security.lockout_tier1_seconds',
                'security.lockout_tier2_attempts',
                'security.lockout_tier2_seconds',
                'security.lockout_tier3_attempts',
                'security.lockout_tier3_seconds',
                'security.lockout_window_minutes',
                'security.password_min_length',
                'security.password_require_uppercase',
                'security.password_require_lowercase',
                'security.password_require_digit',
                'security.password_require_symbol',
                'security.password_block_common',
                'security.password_block_email_match',
                'security.headers_enabled',
                'security.headers_hsts_enabled',
                'security.headers_hsts_max_age',
                'security.headers_frame_options',
                'security.headers_referrer_policy',
                'security.headers_permissions_policy',
            ],
            default => [], // mail — optional
        };
    }

    /**
     * Whether a setting row has a meaningful (non-null, non-empty) value.
     * Encrypted rows are "set" if their stored ciphertext decrypts to a
     * non-empty string.
     */
    private function isEffectivelySet(SystemSetting $row): bool
    {
        if ($row->is_encrypted) {
            return $this->hasEncryptedValue($row);
        }
        return $row->value !== null && $row->value !== '';
    }

    private function hasEncryptedValue(SystemSetting $row): bool
    {
        if ($row->value === null || $row->value === '') {
            return false;
        }
        try {
            $plain = Crypt::decryptString((string) $row->value);
            return $plain !== '';
        } catch (\Throwable) {
            // Corrupt ciphertext counts as "no usable value".
            return false;
        }
    }

    private function audit(string $section, array $diff, Request $request): void
    {
        try {
            AuditLog::create([
                'module' => 'system_settings',
                'record_id' => $section,
                'action' => 'settings.update',
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name ?? 'admin',
                'user_role' => $request->user()->role ?? 'superadmin',
                'section' => $section,
                'changes' => $diff,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // Audit failure must not block a settings save.
            Log::warning('SystemSettings audit failed', ['error' => $e->getMessage()]);
        }
    }
}
