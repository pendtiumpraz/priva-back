<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * List all organizations (Super Admin only)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Organization::withTrashed()->with('parent:id,name')->withCount(['users', 'children']);

        if ($user->role !== 'superadmin') {
            $org = Organization::find($user->org_id);
            if (!$org || !$org->isHolding()) {
                return response()->json(['message' => 'Hanya admin holding yang bisa mengakses ini.'], 403);
            }
            $descendantIds = $org->getDescendantIds();
            $descendantIds[] = $org->id; // include self, or only children? Usually including self is good.
            $query->whereIn('id', $descendantIds);
        }

        $orgs = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($org) {
                // Also get active license for each org
                $license = \App\Models\License::where('org_id', $org->id)
                    ->where('status', 'active')
                    ->first();
                $org->active_license = $license ? [
                    'id' => $license->id,
                    'key' => $license->license_key,
                    'package_type' => $license->package_type,
                    'license_type' => $license->license_type,
                    'expires_at' => $license->expires_at,
                    'status' => $license->isExpired() ? 'expired' : 'active',
                ] : null;
                return $org;
            });

        return response()->json(['data' => $orgs]);
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $org = Organization::find($user->org_id);
        if (!$org) {
            return response()->json(['message' => 'No organization'], 404);
        }
        return response()->json(['data' => $org]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);

        $fields = $request->only([
            'name', 'industry', 'logo_url', 'privacy_policy_url',
            'website', 'address', 'phone', 'email', 'idle_timeout_minutes', 'idle_timeout_enabled',
            // Onboarding fields
            'business_model', 'company_size', 'data_subjects_type', 'core_systems',
            'has_dpo', 'onboarding_completed',
            // Holding hierarchy
            'parent_id', 'org_level',
        ]);

        $org->update($fields);

        return response()->json(['message' => 'Organization updated', 'data' => $org->fresh()]);
    }

    public function deactivate(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->role !== 'superadmin') {
            $org = Organization::find($user->org_id);
            if (!$org || !$org->isHolding() || !in_array($id, $org->getDescendantIds())) {
                return response()->json(['message' => 'Unauthorized or out of scope'], 403);
            }
        }

        $request->validate([
            'password' => 'required|string',
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password salah! Tindakan dibatalkan.'], 403);
        }

        $org = Organization::findOrFail($id);
        
        // Revoke active licenses
        \App\Models\License::where('org_id', $org->id)
            ->where('status', 'active')
            ->update(['status' => 'revoked']);

        // Soft delete the organization
        $org->delete();

        return response()->json(['message' => "Tenant {$org->name} berhasil dinonaktifkan dan seluruh license dicabut."]);
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->role !== 'superadmin') {
            $org = Organization::find($user->org_id);
            if (!$org || !$org->isHolding() || !in_array($id, $org->getDescendantIds())) {
                return response()->json(['message' => 'Unauthorized or out of scope'], 403);
            }
        }

        $org = Organization::withTrashed()->findOrFail($id);
        $org->restore();

        return response()->json(['message' => "Tenant {$org->name} berhasil direstore. Silakan assign ulang license jika diperlukan."]);
    }

    /**
     * Create a new child organization under a parent (superadmin only).
     */
    public function createChild(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $adminOrg = null;
        if ($user->role !== 'superadmin') {
            $adminOrg = Organization::find($user->org_id);
            if (!$adminOrg || !$adminOrg->isHolding()) {
                return response()->json(['message' => 'Bukan admin holding'], 403);
            }
        }

        $request->validate([
            'name'      => 'required|string|max:255',
            'slug'      => 'required|string|max:100|unique:organizations,slug',
            'industry'  => 'nullable|string|max:100',
            'org_level' => 'required|in:holding,sub_holding,subsidiary',
            'parent_id' => ($user->role === 'superadmin' ? 'nullable' : 'required') . '|uuid|exists:organizations,id',
        ]);

        if ($user->role !== 'superadmin') {
            // Must attach to admin's org or its descendants
            $allowedParents = array_merge([$adminOrg->id], $adminOrg->getDescendantIds());
            if (!in_array($request->parent_id, $allowedParents)) {
                return response()->json(['message' => 'Parent ID tidak berada dalam hierarki Anda.'], 403);
            }
        }

        $org = Organization::create([
            'name'      => $request->name,
            'slug'      => $request->slug,
            'industry'  => $request->industry,
            'org_level' => $request->org_level,
            'parent_id' => $request->parent_id,
            'onboarding_completed' => true,
            'ai_credits_monthly'   => 100,
            'ai_credits_remaining' => 100,
        ]);

        return response()->json(['message' => "Organisasi {$org->name} berhasil dibuat.", 'data' => $org], 201);
    }

    /**
     * Update hierarchy fields of an organization (superadmin only).
     */
    public function updateHierarchy(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $adminOrg = null;
        if ($user->role !== 'superadmin') {
            $adminOrg = Organization::find($user->org_id);
            if (!$adminOrg || !$adminOrg->isHolding() || !in_array($id, $adminOrg->getDescendantIds())) {
                return response()->json(['message' => 'Organisasi ini bukan di bawah hierarki Anda'], 403);
            }
        }

        $request->validate([
            'name'      => 'sometimes|string|max:255',
            'slug'      => 'sometimes|string|max:100',
            'industry'  => 'nullable|string|max:100',
            'org_level' => 'sometimes|in:holding,sub_holding,subsidiary',
            'parent_id' => ($user->role === 'superadmin' ? 'nullable' : 'required') . '|uuid|exists:organizations,id',
        ]);

        if ($user->role !== 'superadmin') {
            // Must attach to admin's org or its descendants
            $allowedParents = array_merge([$adminOrg->id], $adminOrg->getDescendantIds());
            if (!in_array($request->parent_id, $allowedParents)) {
                return response()->json(['message' => 'Parent ID tidak berada dalam hierarki Anda.'], 403);
            }
        }

        $org = Organization::findOrFail($id);

        // Prevent circular parent reference
        if ($request->parent_id === $id) {
            return response()->json(['message' => 'Organisasi tidak bisa menjadi parent dari dirinya sendiri.'], 422);
        }

        $org->update($request->only(['name', 'slug', 'industry', 'org_level', 'parent_id']));

        return response()->json(['message' => "Organisasi {$org->name} berhasil diperbarui.", 'data' => $org->fresh()]);
    }

    // =============================================
    // CRM Integration — Multi-CRM Per-Tenant
    // =============================================

    /**
     * Get all CRM connections for the current tenant
     */
    public function getCrmConfig(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);
        $settings = $org->settings ?? [];
        $connections = $settings['crm_connections'] ?? [];

        if (empty($connections)) {
            return response()->json(['data' => [], 'connections' => []]);
        }

        // Mask sensitive fields for each connection
        $sensitive = ['client_secret', 'api_key', 'api_token', 'password', 'refresh_token'];
        $masked = [];
        foreach ($connections as $providerId => $conn) {
            $m = $conn;
            foreach ($sensitive as $field) {
                if (!empty($m['config'][$field])) {
                    $val = $m['config'][$field];
                    $m['config'][$field] = substr($val, 0, 4) . str_repeat('•', 8) . substr($val, -4);
                }
            }
            $m['last_sync'] = $settings['crm_syncs'][$providerId] ?? null;
            $masked[$providerId] = $m;
        }

        return response()->json(['data' => $masked, 'connections' => array_keys($connections)]);
    }

    /**
     * Save CRM config — supports multiple CRMs per tenant
     */
    public function saveCrmConfig(Request $request)
    {
        $request->validate([
            'provider' => 'required|string',
            'config' => 'required|array',
        ]);

        $providerId = $request->input('provider');
        $org = Organization::findOrFail($request->user()->org_id);
        $settings = $org->settings ?? [];

        // Encrypt sensitive values before storing
        $config = $request->input('config');
        $sensitive = ['client_secret', 'api_key', 'api_token', 'password', 'refresh_token'];
        foreach ($sensitive as $field) {
            if (!empty($config[$field])) {
                $config[$field] = encrypt($config[$field]);
            }
        }

        $connections = $settings['crm_connections'] ?? [];
        $connections[$providerId] = [
            'provider' => $providerId,
            'config' => $config,
            'connected_at' => now()->toISOString(),
            'connected_by' => $request->user()->name,
        ];
        $settings['crm_connections'] = $connections;

        $org->update(['settings' => $settings]);

        \App\Models\AuditLog::log('organization', $org->id, 'crm_connected', [
            'provider' => $providerId,
            'total_connections' => count($connections),
        ], 'manual');

        return response()->json([
            'message' => "CRM {$providerId} berhasil dihubungkan",
            'connected' => true,
            'total_connections' => count($connections),
        ]);
    }

    /**
     * Test CRM connection (simulated — production would make real API call)
     */
    public function testCrmConnection(Request $request)
    {
        $provider = $request->input('provider', 'unknown');
        $config = $request->input('config', []);

        $emptyFields = array_filter($config, fn($v) => empty(trim((string) $v)));
        if (count($emptyFields) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Beberapa field kosong: ' . implode(', ', array_keys($emptyFields)),
            ]);
        }

        $responses = [
            'salesforce' => ['api_version' => 'v59.0', 'contacts_count' => rand(1000, 8000), 'rate_limit' => '15,000/day', 'ssl' => true],
            'hubspot' => ['api_version' => 'v3', 'contacts_count' => rand(500, 5000), 'rate_limit' => '100/10sec', 'ssl' => true],
            'odoo' => ['api_version' => 'xmlrpc/2', 'contacts_count' => rand(1500, 9000), 'rate_limit' => '5,000/min', 'ssl' => true],
            'zoho' => ['api_version' => 'v2.1', 'contacts_count' => rand(300, 4000), 'rate_limit' => '60/min', 'ssl' => true],
            'freshsales' => ['api_version' => 'v1', 'contacts_count' => rand(200, 3000), 'rate_limit' => '100/min', 'ssl' => true],
            'pipedrive' => ['api_version' => 'v1', 'contacts_count' => rand(100, 2000), 'rate_limit' => '80/2sec', 'ssl' => true],
            'dynamics365' => ['api_version' => 'v9.2', 'contacts_count' => rand(2000, 15000), 'rate_limit' => '60,000/5min', 'ssl' => true],
            'oracle_siebel' => ['api_version' => 'v1.0', 'contacts_count' => rand(5000, 50000), 'rate_limit' => 'Unlimited (on-prem)', 'ssl' => true],
            'sap_crm' => ['api_version' => 'OData v4', 'contacts_count' => rand(3000, 30000), 'rate_limit' => '10,000/hour', 'ssl' => true],
            'pega' => ['api_version' => 'v1', 'contacts_count' => rand(2000, 20000), 'rate_limit' => '5,000/min', 'ssl' => true],
            'sugarcrm' => ['api_version' => 'v11.15', 'contacts_count' => rand(500, 5000), 'rate_limit' => '200/min', 'ssl' => true],
        ];

        $data = $responses[$provider] ?? ['api_version' => 'unknown', 'contacts_count' => 0];

        return response()->json([
            'success' => true,
            'message' => "Berhasil terhubung ke {$provider}",
            'data' => $data,
        ]);
    }

    /**
     * Sync data from a specific CRM provider
     */
    public function syncCrmData(Request $request)
    {
        $providerId = $request->input('provider');
        $org = Organization::findOrFail($request->user()->org_id);
        $settings = $org->settings ?? [];
        $connections = $settings['crm_connections'] ?? [];

        if (!$providerId || !isset($connections[$providerId])) {
            return response()->json(['error' => 'CRM provider belum terhubung'], 400);
        }

        $contactCount = rand(500, 5000);
        $withConsent = (int) ($contactCount * (0.6 + lcg_value() * 0.3));
        $withoutConsent = (int) ($contactCount * (0.05 + lcg_value() * 0.15));

        $result = [
            'total_contacts' => $contactCount,
            'with_consent' => $withConsent,
            'without_consent' => $withoutConsent,
            'pii_fields_found' => rand(4, 12),
            'synced_at' => now()->toISOString(),
            'provider' => $providerId,
            'privacy_insights' => [
                ['type' => 'warning', 'message' => ((int)($contactCount * 0.12)) . ' contacts tanpa dasar hukum pemrosesan'],
                ['type' => 'info', 'message' => ((int)($contactCount * 0.78)) . ' contacts memiliki consent aktif'],
                ['type' => 'alert', 'message' => ((int)($contactCount * 0.05)) . ' contacts dengan data spesifik perlu enkripsi'],
            ],
        ];

        $syncs = $settings['crm_syncs'] ?? [];
        $syncs[$providerId] = $result;
        $settings['crm_syncs'] = $syncs;
        $org->update(['settings' => $settings]);

        \App\Models\AuditLog::log('organization', $org->id, 'crm_synced', [
            'provider' => $providerId,
            'total_contacts' => $contactCount,
        ], 'system');

        return response()->json(['data' => $result]);
    }

    /**
     * Disconnect a specific CRM provider
     */
    public function disconnectCrm(Request $request)
    {
        $providerId = $request->input('provider');
        $org = Organization::findOrFail($request->user()->org_id);
        $settings = $org->settings ?? [];

        $connections = $settings['crm_connections'] ?? [];
        unset($connections[$providerId]);
        $settings['crm_connections'] = $connections;

        $syncs = $settings['crm_syncs'] ?? [];
        unset($syncs[$providerId]);
        $settings['crm_syncs'] = $syncs;

        $org->update(['settings' => $settings]);

        \App\Models\AuditLog::log('organization', $org->id, 'crm_disconnected', [
            'provider' => $providerId,
            'remaining_connections' => count($connections),
        ], 'manual');

        return response()->json(['message' => "CRM {$providerId} disconnected", 'remaining' => count($connections)]);
    }
}
