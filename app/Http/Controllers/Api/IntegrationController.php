<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{
    /**
     * Available integration providers with field definitions.
     */
    private const PROVIDERS = [
        'telegram' => [
            'name' => 'Telegram Bot',
            'icon' => '📱',
            'desc' => 'Kirim notifikasi breach ke Telegram channel atau group',
            'fields' => [
                ['key' => 'bot_token', 'label' => 'Bot Token', 'type' => 'password', 'placeholder' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', 'required' => true],
                ['key' => 'chat_id', 'label' => 'Chat ID', 'type' => 'text', 'placeholder' => '-1001234567890', 'required' => true],
            ],
            'secret_fields' => ['bot_token'],
            'help' => 'Buat bot via @BotFather, lalu add ke group/channel. Chat ID bisa didapat dari @userinfobot.',
        ],
        'siem' => [
            'name' => 'SIEM',
            'icon' => '🔍',
            'desc' => 'Kirim event ke SIEM (Splunk, QRadar, Wazuh, Elastic SIEM, dll)',
            'fields' => [
                ['key' => 'type', 'label' => 'SIEM Type', 'type' => 'select', 'options' => ['splunk', 'qradar', 'wazuh', 'elastic', 'sentinel', 'custom'], 'required' => true],
                ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'text', 'placeholder' => 'https://your-siem.com/api/events', 'required' => true],
                ['key' => 'api_key', 'label' => 'API Key / Token', 'type' => 'password', 'placeholder' => 'Bearer token atau HEC token', 'required' => false],
                ['key' => 'format', 'label' => 'Format', 'type' => 'select', 'options' => ['json', 'cef', 'leef', 'syslog'], 'required' => false],
            ],
            'secret_fields' => ['api_key'],
            'help' => 'Untuk Splunk, gunakan HEC token. Untuk QRadar, gunakan API Key. Format CEF/LEEF hanya untuk kompatibilitas tertentu.',
        ],
        'soar' => [
            'name' => 'SOAR Platform',
            'icon' => '⚡',
            'desc' => 'Trigger playbook otomatis saat breach terjadi (TheHive, Cortex, Phantom, dll)',
            'fields' => [
                ['key' => 'platform', 'label' => 'Platform', 'type' => 'select', 'options' => ['thehive', 'cortex_xsoar', 'phantom', 'shuffle', 'custom'], 'required' => true],
                ['key' => 'endpoint', 'label' => 'API Endpoint', 'type' => 'text', 'placeholder' => 'https://your-soar.com/api/v1/case', 'required' => true],
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'API Key', 'required' => true],
                ['key' => 'playbook_id', 'label' => 'Playbook / Template ID', 'type' => 'text', 'placeholder' => 'playbook-breach-response', 'required' => false],
                ['key' => 'auto_trigger', 'label' => 'Auto Trigger', 'type' => 'checkbox', 'desc' => 'Otomatis trigger playbook saat breach severity high/critical'],
            ],
            'secret_fields' => ['api_key'],
            'help' => 'SOAR akan otomatis membuat case/incident dan menjalankan playbook untuk response otomatis.',
        ],
        'socradar' => [
            'name' => 'SOCRadar',
            'icon' => '🛡️',
            'desc' => 'Terima alert dari SOCRadar untuk deteksi breach dari dark web & threat intel',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'SOCRadar API Key', 'required' => false],
                ['key' => 'company_id', 'label' => 'Company ID', 'type' => 'text', 'placeholder' => 'Company ID dari SOCRadar', 'required' => false],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'placeholder' => 'Auto-generated jika kosong', 'required' => false],
                ['key' => 'webhook_url', 'label' => 'Webhook URL (readonly)', 'type' => 'readonly'],
            ],
            'secret_fields' => ['api_key', 'webhook_secret'],
            'help' => 'Webhook URL akan otomatis digenerate. Copy URL ini ke konfigurasi webhook SOCRadar.',
        ],
    ];

    /**
     * GET /api/integrations
     * Get all integration configs for this tenant (secrets masked).
     */
    public function index(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);
        $raw = $this->decryptConfig($org);

        $result = [];
        foreach (self::PROVIDERS as $key => $provider) {
            $config = $raw[$key] ?? [];
            $maskedConfig = [];

            foreach ($provider['fields'] as $field) {
                $fKey = $field['key'];
                if ($fKey === 'webhook_url') {
                    // Generate webhook URL dynamically
                    $maskedConfig[$fKey] = url("/api/webhooks/threat-intel/{$org->id}");
                    continue;
                }
                if (isset($config[$fKey]) && in_array($fKey, $provider['secret_fields'])) {
                    $val = $config[$fKey];
                    $maskedConfig[$fKey] = strlen($val) > 8
                        ? substr($val, 0, 4) . '••••' . substr($val, -4)
                        : '••••••••';
                } else {
                    $maskedConfig[$fKey] = $config[$fKey] ?? '';
                }
            }

            $result[$key] = [
                'enabled' => $config['enabled'] ?? false,
                'config' => $maskedConfig,
                'provider' => [
                    'name' => $provider['name'],
                    'icon' => $provider['icon'],
                    'desc' => $provider['desc'],
                    'help' => $provider['help'],
                    'fields' => $provider['fields'],
                ],
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * PUT /api/integrations/{provider}
     * Save integration config for a specific provider.
     */
    public function update(string $provider, Request $request)
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return response()->json(['message' => 'Provider tidak valid.'], 404);
        }

        $providerDef = self::PROVIDERS[$provider];

        $org = Organization::findOrFail($request->user()->org_id);
        $allConfig = $this->decryptConfig($org);
        $existing = $allConfig[$provider] ?? [];

        $newConfig = ['enabled' => (bool) $request->input('enabled', false)];

        foreach ($providerDef['fields'] as $field) {
            $fKey = $field['key'];
            if ($fKey === 'webhook_url') continue; // readonly
            $val = $request->input($fKey);
            if ($val !== null && $val !== '') {
                // SSRF guard untuk field URL yang user-supplied. Validate
                // sebelum simpan — gak save URL yang resolve ke private IP.
                if (str_ends_with($fKey, '_url') || str_contains($fKey, 'url')) {
                    try {
                        app(\App\Services\OutboundUrlValidator::class)->validate($val);
                    } catch (\RuntimeException $e) {
                        return response()->json([
                            'message' => "URL '{$fKey}' ditolak: " . $e->getMessage(),
                        ], 422);
                    }
                }
                $newConfig[$fKey] = $val;
            } elseif (isset($existing[$fKey])) {
                $newConfig[$fKey] = $existing[$fKey]; // keep old
            }
        }

        // Auto-generate webhook secret for SOCRadar if not provided
        if ($provider === 'socradar' && empty($newConfig['webhook_secret'])) {
            $newConfig['webhook_secret'] = bin2hex(random_bytes(16));
        }

        $allConfig[$provider] = $newConfig;
        $org->update(['integration_config' => Crypt::encryptString(json_encode($allConfig))]);

        return response()->json([
            'message' => $providerDef['name'] . ' berhasil disimpan.',
            'webhook_secret' => ($provider === 'socradar' && !isset($existing['webhook_secret']))
                ? $newConfig['webhook_secret'] : null,
        ]);
    }

    /**
     * POST /api/integrations/{provider}/test
     * Test the integration connection.
     */
    public function test(string $provider, Request $request)
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return response()->json(['message' => 'Provider tidak valid.'], 404);
        }

        $org = Organization::findOrFail($request->user()->org_id);
        $allConfig = $this->decryptConfig($org);
        $config = $allConfig[$provider] ?? [];

        // Merge with request data (testing before saving)
        foreach (self::PROVIDERS[$provider]['fields'] as $field) {
            $fKey = $field['key'];
            if ($request->has($fKey) && $request->input($fKey)) {
                $config[$fKey] = $request->input($fKey);
            }
        }

        try {
            switch ($provider) {
                case 'telegram': return $this->testTelegram($config);
                case 'siem': return $this->testSiem($config);
                case 'soar': return $this->testSoar($config);
                case 'socradar': return $this->testSocradar($config);
                default: return response()->json(['success' => false, 'message' => 'Test tidak tersedia.']);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * DELETE /api/integrations/{provider}
     * Remove integration config for a provider.
     */
    public function destroy(string $provider, Request $request)
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return response()->json(['message' => 'Provider tidak valid.'], 404);
        }

        $org = Organization::findOrFail($request->user()->org_id);
        $allConfig = $this->decryptConfig($org);
        unset($allConfig[$provider]);

        $org->update([
            'integration_config' => empty($allConfig) ? null : Crypt::encryptString(json_encode($allConfig)),
        ]);

        return response()->json(['message' => self::PROVIDERS[$provider]['name'] . ' dihapus.']);
    }

    // ==================== Breach Sync (existing functionality) ====================

    /**
     * Send breach to Telegram War Room.
     *
     * Uses the Eloquent model (not raw DB::table) so EncryptedString casts
     * apply — otherwise the description/title ciphertext gets pushed to
     * Telegram unreadable. See NOTIFICATION_SYSTEM_PLAN.md.
     */
    public function syncBreachTelegram(Request $request, $id)
    {
        try {
            $breach = \App\Models\BreachIncident::where('org_id', $request->user()->org_id)->find($id);
            if (!$breach) return response()->json(['error' => 'Breach not found'], 404);

            $org = Organization::findOrFail($request->user()->org_id);
            $config = $this->getProviderConfig($org, 'telegram');

            if (empty($config['bot_token']) || empty($config['chat_id'])) {
                return response()->json([
                    'success' => false, 'is_missing_config' => true,
                    'message' => 'Telegram Bot Token & Chat ID belum dikonfigurasi.',
                ], 400);
            }

            $message = \App\Services\NotificationService::buildBreachTelegramMessage($breach);

            $response = Http::post("https://api.telegram.org/bot{$config['bot_token']}/sendMessage", [
                'chat_id' => $config['chat_id'], 'text' => $message, 'parse_mode' => 'Markdown',
            ]);

            if ($response->successful()) {
                return response()->json(['success' => true, 'message' => 'Message sent to Telegram.']);
            }
            throw new \Exception($response->body());
        } catch (\Exception $e) {
            Log::error("Telegram Sync Error: " . $e->getMessage());
            return response()->json(['error' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Send breach to SIEM/SOAR
     */
    public function syncBreachSiem(Request $request, $id)
    {
        try {
            // Same fix as syncBreachTelegram — use Eloquent so EncryptedString
            // casts apply; raw DB::table bypasses them and leaks ciphertext.
            $breach = \App\Models\BreachIncident::where('org_id', $request->user()->org_id)->find($id);
            if (!$breach) return response()->json(['error' => 'Breach not found'], 404);

            $org = Organization::findOrFail($request->user()->org_id);
            $config = $this->getProviderConfig($org, 'siem');

            if (empty($config['endpoint'])) {
                return response()->json([
                    'success' => false, 'is_missing_config' => true,
                    'message' => 'SIEM Endpoint belum dikonfigurasi.',
                ], 400);
            }

            $headers = [];
            if (!empty($config['api_key'])) {
                $headers['Authorization'] = 'Bearer ' . $config['api_key'];
            }

            $payload = [
                'event_source' => 'PRIVASIMU',
                'event_type' => 'Data Breach Incident',
                'incident_code' => $breach->incident_code,
                'severity' => $breach->severity,
                'title' => $breach->title,
                'description' => $breach->description,
                'status' => $breach->status,
                'affected_subjects' => $breach->affected_subjects_count,
                'timestamp' => now()->toIso8601String(),
                'original_detected_at' => $breach->detected_at,
            ];

            $response = Http::withHeaders($headers)->post($config['endpoint'], $payload);

            if ($response->successful()) {
                return response()->json(['success' => true, 'message' => 'Event broadcasted to SIEM.']);
            }
            throw new \Exception($response->body());
        } catch (\Exception $e) {
            Log::error("SIEM Sync Error: " . $e->getMessage());
            return response()->json(['error' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    // ==================== Test Helpers ====================

    private function testTelegram(array $config)
    {
        if (empty($config['bot_token']) || empty($config['chat_id'])) {
            return response()->json(['success' => false, 'message' => 'Bot Token dan Chat ID wajib.']);
        }
        $res = Http::post("https://api.telegram.org/bot{$config['bot_token']}/sendMessage", [
            'chat_id' => $config['chat_id'],
            'text' => "✅ *PRIVASIMU Test*\n\nKoneksi Telegram berhasil! Breach alert akan dikirim ke sini.",
            'parse_mode' => 'Markdown',
        ]);
        return $res->ok() && $res->json('ok')
            ? response()->json(['success' => true, 'message' => 'Pesan test terkirim ke Telegram!'])
            : response()->json(['success' => false, 'message' => 'Gagal: ' . ($res->json('description') ?? 'Unknown')]);
    }

    private function testSiem(array $config)
    {
        if (empty($config['endpoint'])) {
            return response()->json(['success' => false, 'message' => 'Endpoint wajib.']);
        }
        $headers = !empty($config['api_key']) ? ['Authorization' => 'Bearer ' . $config['api_key']] : [];
        $res = Http::withHeaders($headers)->timeout(10)->post($config['endpoint'], [
            'event_type' => 'test', 'source' => 'privasimu', 'message' => 'SIEM connection test', 'timestamp' => now()->toISOString(),
        ]);
        return $res->successful()
            ? response()->json(['success' => true, 'message' => "SIEM terhubung! Status: {$res->status()}"])
            : response()->json(['success' => false, 'message' => "Gagal. Status: {$res->status()}"]);
    }

    private function testSoar(array $config)
    {
        if (empty($config['endpoint'])) {
            return response()->json(['success' => false, 'message' => 'Endpoint wajib.']);
        }
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($config['api_key'])) $headers['Authorization'] = 'Bearer ' . $config['api_key'];
        $res = Http::withHeaders($headers)->timeout(10)->get($config['endpoint']);
        return $res->successful()
            ? response()->json(['success' => true, 'message' => "SOAR terhubung! Status: {$res->status()}"])
            : response()->json(['success' => false, 'message' => "Gagal. Status: {$res->status()}"]);
    }

    private function testSocradar(array $config)
    {
        if (empty($config['api_key'])) {
            return response()->json(['success' => false, 'message' => 'API Key wajib.']);
        }
        $res = Http::withHeaders(['Authorization' => 'Bearer ' . $config['api_key']])
            ->timeout(10)->get("https://platform.socradar.com/api/v2/company/" . ($config['company_id'] ?? '') . "/incidents");
        return $res->successful()
            ? response()->json(['success' => true, 'message' => 'SOCRadar terhubung!'])
            : response()->json(['success' => false, 'message' => "Gagal. Status: {$res->status()}"]);
    }

    // ==================== Helpers ====================

    private function decryptConfig(Organization $org): array
    {
        // Try new encrypted field first, fallback to old settings
        if ($org->integration_config) {
            try {
                return json_decode(Crypt::decryptString($org->integration_config), true) ?? [];
            } catch (\Exception $e) {}
        }
        // Migrate from old settings format
        $settings = $org->settings ?? [];
        if (!empty($settings['telegram_bot_token'])) {
            return [
                'telegram' => [
                    'enabled' => true,
                    'bot_token' => $settings['telegram_bot_token'] ?? '',
                    'chat_id' => $settings['telegram_chat_id'] ?? '',
                ],
                'siem' => [
                    'enabled' => !empty($settings['siem_webhook_url']),
                    'endpoint' => $settings['siem_webhook_url'] ?? '',
                    'type' => 'custom',
                ],
            ];
        }
        return [];
    }

    private function getProviderConfig(Organization $org, string $provider): array
    {
        $all = $this->decryptConfig($org);
        return $all[$provider] ?? [];
    }
}
