<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\PartnerApiKey;
use App\Models\Webhook;
use Illuminate\Http\Request;

class ApiHubController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if ($request->user()->role !== 'root') {
                return response()->json(['message' => 'Forbidden. Root only.'], 403);
            }

            return $next($request);
        });
    }

    // ==================== API KEYS ====================

    /**
     * List all API keys for the tenant.
     */
    public function listKeys(Request $request)
    {
        $keys = PartnerApiKey::where('org_id', $request->user()->org_id)
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $keys]);
    }

    /**
     * Generate a new API key.
     */
    public function createKey(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'string',
            'allowed_ips' => 'nullable|array',
            'environment' => 'required|in:live,test',
            'rate_limit_per_minute' => 'nullable|integer|min:10|max:1000',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $user = $request->user();

        $result = PartnerApiKey::generateKey([
            'org_id' => $user->org_id,
            'name' => $request->name,
            'permissions' => $request->permissions,
            'allowed_ips' => $request->allowed_ips,
            'environment' => $request->environment,
            'rate_limit_per_minute' => $request->rate_limit_per_minute ?? 60,
            'expires_at' => $request->expires_at,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'API key berhasil dibuat. Simpan key ini — tidak bisa ditampilkan lagi!',
            'data' => [
                'key' => $result['key'], // Only shown once!
                'model' => $result['model'],
            ],
        ], 201);
    }

    /**
     * Toggle API key active/inactive.
     */
    public function toggleKey(string $id, Request $request)
    {
        $key = PartnerApiKey::where('org_id', $request->user()->org_id)->findOrFail($id);
        $key->update(['is_active' => ! $key->is_active]);

        return response()->json([
            'message' => $key->is_active ? 'API key diaktifkan.' : 'API key dinonaktifkan.',
            'data' => $key,
        ]);
    }

    /**
     * Delete an API key.
     */
    public function deleteKey(string $id, Request $request)
    {
        $key = PartnerApiKey::where('org_id', $request->user()->org_id)->findOrFail($id);
        $key->delete();

        return response()->json(['message' => 'API key dihapus.']);
    }

    // ==================== USAGE STATS ====================

    /**
     * Get API usage statistics.
     */
    public function usage(Request $request)
    {
        $orgId = $request->user()->org_id;
        $days = $request->days ?? 30;

        // Total requests
        $totalRequests = ApiRequestLog::where('org_id', $orgId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        // Requests by day
        $dailyStats = ApiRequestLog::where('org_id', $orgId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as requests, AVG(response_time_ms) as avg_response_ms')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Requests by endpoint
        $endpointStats = ApiRequestLog::where('org_id', $orgId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('endpoint, method, COUNT(*) as requests, AVG(response_time_ms) as avg_response_ms')
            ->groupBy('endpoint', 'method')
            ->orderByDesc('requests')
            ->limit(20)
            ->get();

        // Requests by API key
        $keyStats = ApiRequestLog::where('api_request_logs.org_id', $orgId)
            ->where('api_request_logs.created_at', '>=', now()->subDays($days))
            ->join('partner_api_keys', 'api_request_logs.api_key_id', '=', 'partner_api_keys.id')
            ->selectRaw('partner_api_keys.name as key_name, partner_api_keys.key_prefix, COUNT(*) as requests')
            ->groupBy('partner_api_keys.name', 'partner_api_keys.key_prefix')
            ->orderByDesc('requests')
            ->get();

        // Error rate
        $errorCount = ApiRequestLog::where('org_id', $orgId)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status_code', '>=', 400)
            ->count();

        return response()->json([
            'data' => [
                'period_days' => $days,
                'total_requests' => $totalRequests,
                'error_rate' => $totalRequests > 0 ? round(($errorCount / $totalRequests) * 100, 2) : 0,
                'daily' => $dailyStats,
                'by_endpoint' => $endpointStats,
                'by_key' => $keyStats,
            ],
        ]);
    }

    // ==================== WEBHOOKS ====================

    /**
     * List webhooks.
     */
    public function listWebhooks(Request $request)
    {
        $webhooks = Webhook::where('org_id', $request->user()->org_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $webhooks]);
    }

    /**
     * Create a webhook.
     */
    public function createWebhook(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:1000',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:breach.created,breach.updated,breach.status_changed,breach.closed,threat_intel.inbound',
            'secret' => 'nullable|string|max:255',
        ]);

        $webhook = Webhook::create([
            'org_id' => $request->user()->org_id,
            'name' => $request->name,
            'url' => $request->url,
            'events' => $request->events,
            'secret' => $request->secret ?: bin2hex(random_bytes(16)),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Webhook berhasil dibuat.',
            'data' => $webhook,
        ], 201);
    }

    /**
     * Toggle webhook active/inactive.
     */
    public function toggleWebhook(string $id, Request $request)
    {
        $webhook = Webhook::where('org_id', $request->user()->org_id)->findOrFail($id);
        $webhook->update(['is_active' => ! $webhook->is_active]);

        return response()->json([
            'message' => $webhook->is_active ? 'Webhook diaktifkan.' : 'Webhook dinonaktifkan.',
            'data' => $webhook,
        ]);
    }

    /**
     * Delete a webhook.
     */
    public function deleteWebhook(string $id, Request $request)
    {
        Webhook::where('org_id', $request->user()->org_id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Webhook dihapus.']);
    }

    // ==================== API DOCS ====================

    /**
     * Return API documentation structure.
     */
    public function docs()
    {
        return response()->json([
            'data' => [
                'base_url' => url('/api/v1'),
                'auth' => [
                    'type' => 'header',
                    'header' => 'X-Api-Key',
                    'format' => 'pk_live_xxxxxxxx',
                ],
                'modules' => [
                    [
                        'name' => 'Breach Management',
                        'prefix' => '/breach',
                        'endpoints' => [
                            [
                                'method' => 'GET',
                                'path' => '/breach',
                                'description' => 'List semua breach incidents',
                                'permissions' => ['breach.read'],
                                'params' => [
                                    ['name' => 'status', 'type' => 'string', 'required' => false, 'values' => 'open,assessing,containing,contained,closed'],
                                    ['name' => 'severity', 'type' => 'string', 'required' => false, 'values' => 'low,medium,high,critical'],
                                    ['name' => 'since', 'type' => 'date', 'required' => false],
                                    ['name' => 'until', 'type' => 'date', 'required' => false],
                                    ['name' => 'search', 'type' => 'string', 'required' => false],
                                    ['name' => 'per_page', 'type' => 'integer', 'required' => false, 'default' => 20],
                                ],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/breach/{id}',
                                'description' => 'Detail breach incident',
                                'permissions' => ['breach.read'],
                            ],
                            [
                                'method' => 'POST',
                                'path' => '/breach',
                                'description' => 'Buat breach incident baru',
                                'permissions' => ['breach.write'],
                                'body' => [
                                    ['name' => 'title', 'type' => 'string', 'required' => true],
                                    ['name' => 'severity', 'type' => 'string', 'required' => true, 'values' => 'low,medium,high,critical'],
                                    ['name' => 'description', 'type' => 'string', 'required' => false],
                                    ['name' => 'source', 'type' => 'string', 'required' => false],
                                    ['name' => 'affected_data_types', 'type' => 'array', 'required' => false],
                                    ['name' => 'affected_subjects_count', 'type' => 'integer', 'required' => false],
                                    ['name' => 'root_cause', 'type' => 'string', 'required' => false],
                                    ['name' => 'detected_at', 'type' => 'datetime', 'required' => false],
                                ],
                            ],
                            [
                                'method' => 'PUT',
                                'path' => '/breach/{id}',
                                'description' => 'Update breach incident',
                                'permissions' => ['breach.write'],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/breach/stats',
                                'description' => 'Statistik breach organisasi',
                                'permissions' => ['breach.read'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Pihak Ketiga (Third Party)',
                        'prefix' => '/third-parties',
                        'endpoints' => [
                            [
                                'method' => 'GET',
                                'path' => '/third-parties',
                                'description' => 'Daftar pihak ketiga organisasi',
                                'permissions' => ['third_party.read'],
                                'params' => [
                                    ['name' => 'search', 'type' => 'string', 'required' => false],
                                    ['name' => 'country', 'type' => 'string', 'required' => false],
                                    ['name' => 'risk_level', 'type' => 'string', 'required' => false, 'values' => 'low,medium,high,critical'],
                                    ['name' => 'lifecycle_status', 'type' => 'string', 'required' => false, 'values' => 'prospective,in_onboarding,active,suspended,offboarding,terminated'],
                                    ['name' => 'external_ref', 'type' => 'string', 'required' => false],
                                    ['name' => 'since', 'type' => 'date', 'required' => false],
                                    ['name' => 'per_page', 'type' => 'integer', 'required' => false, 'default' => 20],
                                ],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/third-parties/{id}',
                                'description' => 'Detail satu pihak ketiga',
                                'permissions' => ['third_party.read'],
                            ],
                            [
                                'method' => 'POST',
                                'path' => '/third-parties',
                                'description' => 'Buat pihak ketiga; bila external_ref sudah dikenal, barisnya DIPERBARUI (upsert)',
                                'permissions' => ['third_party.write'],
                                'body' => [
                                    ['name' => 'name', 'type' => 'string', 'required' => true],
                                    ['name' => 'external_ref', 'type' => 'string', 'required' => false],
                                    ['name' => 'type', 'type' => 'string', 'required' => false, 'values' => 'controller,processor,joint_controller,sub_processor'],
                                    ['name' => 'country', 'type' => 'string', 'required' => false],
                                    ['name' => 'contact_email', 'type' => 'string', 'required' => false],
                                    ['name' => 'services_provided', 'type' => 'array', 'required' => false],
                                    ['name' => 'data_shared', 'type' => 'array', 'required' => false],
                                    ['name' => 'dpa_status', 'type' => 'string', 'required' => false, 'values' => 'none,draft,signed,expired'],
                                ],
                            ],
                            [
                                'method' => 'PUT',
                                'path' => '/third-parties/{id}',
                                'description' => 'Perbarui pihak ketiga',
                                'permissions' => ['third_party.write'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'RoPA (Catatan Kegiatan Pemrosesan)',
                        'prefix' => '/ropa',
                        'endpoints' => [
                            [
                                'method' => 'GET',
                                'path' => '/ropa',
                                'description' => 'Daftar RoPA organisasi (tanpa wizard_data)',
                                'permissions' => ['ropa.read'],
                                'params' => [
                                    ['name' => 'search', 'type' => 'string', 'required' => false],
                                    ['name' => 'risk_level', 'type' => 'string', 'required' => false, 'values' => 'low,medium,high'],
                                    ['name' => 'status', 'type' => 'string', 'required' => false],
                                    ['name' => 'division', 'type' => 'string', 'required' => false],
                                    ['name' => 'legal_basis', 'type' => 'string', 'required' => false],
                                    ['name' => 'since', 'type' => 'date', 'required' => false],
                                    ['name' => 'sort', 'type' => 'string', 'required' => false, 'default' => 'created_at'],
                                    ['name' => 'per_page', 'type' => 'integer', 'required' => false, 'default' => 20],
                                ],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/ropa/stats',
                                'description' => 'Ringkasan jumlah RoPA per tingkat risiko dan status',
                                'permissions' => ['ropa.read'],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/ropa/{id}',
                                'description' => 'Detail RoPA beserta pihak ketiga (dengan perannya) dan DPIA terkait',
                                'permissions' => ['ropa.read'],
                                'params' => [
                                    ['name' => 'include', 'type' => 'string', 'required' => false, 'values' => 'wizard_data'],
                                ],
                            ],
                            [
                                'method' => 'POST',
                                'path' => '/ropa',
                                'description' => 'Buat RoPA. Memakai jalur tulis yang sama dengan aplikasi: penomoran otomatis, perhitungan ulang risiko, DPIA draf otomatis saat risiko tinggi, LIA draf otomatis untuk kepentingan sah, dan sinkronisasi pihak ketiga dari wizard. org_id & created_by ditentukan kunci API, bukan payload.',
                                'permissions' => ['ropa.write'],
                                'body' => [
                                    ['name' => 'processing_activity', 'type' => 'string', 'required' => true],
                                    ['name' => 'purpose', 'type' => 'string', 'required' => false],
                                    ['name' => 'legal_basis', 'type' => 'string', 'required' => false],
                                    ['name' => 'risk_level', 'type' => 'string', 'required' => false, 'values' => 'low,medium,high'],
                                    ['name' => 'status', 'type' => 'string', 'required' => false],
                                    ['name' => 'wizard_data', 'type' => 'object', 'required' => false],
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'DPIA (Penilaian Dampak)',
                        'prefix' => '/dpia',
                        'endpoints' => [
                            [
                                'method' => 'GET',
                                'path' => '/dpia',
                                'description' => 'Daftar DPIA organisasi (tanpa wizard_data)',
                                'permissions' => ['dpia.read'],
                                'params' => [
                                    ['name' => 'search', 'type' => 'string', 'required' => false],
                                    ['name' => 'risk_level', 'type' => 'string', 'required' => false, 'values' => 'low,medium,high'],
                                    ['name' => 'status', 'type' => 'string', 'required' => false],
                                    ['name' => 'ropa_id', 'type' => 'uuid', 'required' => false],
                                    ['name' => 'since', 'type' => 'date', 'required' => false],
                                    ['name' => 'sort', 'type' => 'string', 'required' => false, 'default' => 'created_at'],
                                    ['name' => 'per_page', 'type' => 'integer', 'required' => false, 'default' => 20],
                                ],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/dpia/stats',
                                'description' => 'Ringkasan jumlah DPIA per tingkat risiko dan status',
                                'permissions' => ['dpia.read'],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/dpia/{id}',
                                'description' => 'Detail DPIA beserta seluruh RoPA yang dinaunginya',
                                'permissions' => ['dpia.read'],
                                'params' => [
                                    ['name' => 'include', 'type' => 'string', 'required' => false, 'values' => 'wizard_data'],
                                ],
                            ],
                            [
                                'method' => 'POST',
                                'path' => '/dpia',
                                'description' => 'Buat DPIA. Penomoran DPIA-YYYY-NNN dihitung lintas tenant, dan RoPA yang dinaungi ikut tersinkron dari wizard.koneksi_ropa.',
                                'permissions' => ['dpia.write'],
                                'body' => [
                                    ['name' => 'description', 'type' => 'string', 'required' => false],
                                    ['name' => 'ropa_id', 'type' => 'uuid', 'required' => false],
                                    ['name' => 'risk_level', 'type' => 'string', 'required' => false, 'values' => 'low,medium,high'],
                                    ['name' => 'status', 'type' => 'string', 'required' => false],
                                    ['name' => 'wizard_data', 'type' => 'object', 'required' => false],
                                ],
                            ],
                        ],
                    ],
                ],
                'rate_limiting' => [
                    'default' => '60 requests/minute',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],
                ],
                'webhook_events' => [
                    'breach.created' => 'Saat breach baru dibuat',
                    'breach.updated' => 'Saat breach diperbarui',
                    'breach.status_changed' => 'Saat status breach berubah',
                    'breach.closed' => 'Saat breach ditutup',
                    'threat_intel.inbound' => 'Menerima alert dari threat intel (SOCRadar, dll)',
                ],
                'threat_intel' => [
                    'endpoint' => url('/api/webhooks/threat-intel/{org_id}'),
                    'auth' => 'X-Webhook-Secret header',
                    'description' => 'Endpoint untuk menerima alert dari SOCRadar dan provider threat intelligence lainnya. Auto-create breach incident.',
                    'supported_providers' => ['SOCRadar', 'Generic JSON'],
                ],
            ],
        ]);
    }
}
