<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\PricingPlan;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    // =============================================
    // CRUD — SuperAdmin manages all licenses
    // =============================================

    public function index(Request $request)
    {
        $user = $request->user();

        // SuperAdmin: see all licenses
        // Admin: see only their org's license
        $query = License::query();

        if ($user->role !== 'superadmin') {
            $query->where('org_id', $user->org_id);
        }

        if ($request->search) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('license_key', 'like', "%{$s}%")
                  ->orWhere('org_name', 'like', "%{$s}%")
                  ->orWhere('package_type', 'like', "%{$s}%");
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->package_type) {
            $query->where('package_type', $request->package_type);
        }

        if ($request->trashed === 'true') {
            $query->onlyTrashed();
        }

        $licenses = $query->orderBy('created_at', 'desc')->paginate(20);

        // Mark expired licenses
        foreach ($licenses as $lic) {
            if ($lic->isExpired() && $lic->status === 'active') {
                $lic->update(['status' => 'expired']);
            }
        }

        return response()->json($licenses);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'package_type' => 'required|in:basic,ai,ai_agent',
            'license_type' => 'required|in:perpetual,saas',
            'org_name' => 'nullable|string',
            'org_id' => 'nullable|uuid',
            'domain_whitelist' => 'nullable|array',
            'max_activations' => 'nullable|integer|min:1',
            'duration_days' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $data = $request->all();
        $data['license_key'] = License::generateKey();
        $data['created_by'] = $user->id;
        $data['status'] = 'active';
        $data['max_activations'] = $data['max_activations'] ?? 1;

        // For SaaS: set expiry based on duration
        if ($data['license_type'] === 'saas' && !empty($data['duration_days'])) {
            $data['expires_at'] = now()->addDays($data['duration_days']);
        }

        // Set package features
        $data['features'] = $this->getPackageFeatures($data['package_type']);

        $license = License::create($data);

        return response()->json(['message' => 'License created', 'data' => $license], 201);
    }

    public function show(string $id)
    {
        $license = License::with('activations')->findOrFail($id);
        return response()->json(['data' => $license]);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $license = License::findOrFail($id);
        $data = $request->only([
            'package_type', 'license_type', 'status', 'org_id', 'org_name',
            'domain_whitelist', 'max_activations', 'duration_days', 'notes', 'features',
        ]);

        // Recalculate expiry if duration changed
        if (isset($data['duration_days']) && $license->license_type === 'saas') {
            $base = $license->activated_at ?? now();
            $data['expires_at'] = $base->copy()->addDays($data['duration_days']);
        }

        if (isset($data['package_type'])) {
            $data['features'] = $this->getPackageFeatures($data['package_type']);
        }

        $license->update($data);
        return response()->json(['message' => 'Updated', 'data' => $license->fresh()]);
    }

    public function destroy(string $id)
    {
        $user = request()->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        License::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function restore(string $id)
    {
        $user = request()->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        License::withTrashed()->findOrFail($id)->restore();
        return response()->json(['message' => 'Restored']);
    }

    // =============================================
    // ACTIVATION & VERIFICATION
    // =============================================

    /**
     * Activate a license key (called by admin when inputting license)
     */
    public function activate(Request $request)
    {
        $request->validate([
            'license_key' => 'required|string',
        ]);

        $license = License::where('license_key', $request->license_key)->first();

        if (!$license) {
            return response()->json(['message' => 'License key tidak ditemukan'], 404);
        }

        $ip = $request->ip();
        $domain = $request->header('Origin') ?? $request->header('Referer') ?? 'unknown';
        // Extract just the domain
        $parsedDomain = parse_url($domain, PHP_URL_HOST) ?? $domain;

        // Log the attempt
        LicenseActivation::create([
            'license_id' => $license->id,
            'ip_address' => $ip,
            'domain' => $parsedDomain,
            'server_hostname' => gethostname(),
            'action' => 'activate_attempt',
            'details' => "Activation attempt from {$ip} via {$parsedDomain}",
        ]);

        // Check if expired
        if ($license->isExpired()) {
            return response()->json(['message' => 'License sudah expired. Silahkan perpanjang.', 'status' => 'expired'], 403);
        }

        // Check if revoked
        if ($license->status === 'revoked') {
            return response()->json(['message' => 'License telah direvoke.', 'status' => 'revoked'], 403);
        }

        // Check max activations
        if ($license->activation_count >= $license->max_activations) {
            // Check if same org trying to re-activate
            $user = $request->user();
            if ($user && $license->org_id === $user->org_id) {
                // Same org, allow
            } else {
                // Different org or exceeded! Log warning
                LicenseActivation::create([
                    'license_id' => $license->id,
                    'ip_address' => $ip,
                    'domain' => $parsedDomain,
                    'action' => 'rejected',
                    'details' => "⚠️ PERINGATAN: Percobaan penggunaan license lebih dari {$license->max_activations}x! IP: {$ip}, Domain: {$parsedDomain}",
                ]);

                // Update ip_log with warning
                $ipLog = $license->ip_log ?? [];
                $ipLog[] = [
                    'ip' => $ip,
                    'domain' => $parsedDomain,
                    'at' => now()->toISOString(),
                    'warning' => 'DUPLICATE_USAGE_ATTEMPT',
                ];
                $license->update(['ip_log' => $ipLog]);

                return response()->json([
                    'message' => "⚠️ License ini sudah digunakan {$license->activation_count}x (max: {$license->max_activations}). Percobaan dari IP: {$ip}, Domain: {$parsedDomain} telah dicatat.",
                    'status' => 'exceeded',
                ], 403);
            }
        }

        // Check domain whitelist
        if (!empty($license->domain_whitelist) && is_array($license->domain_whitelist) && count($license->domain_whitelist) > 0) {
            if (!in_array($parsedDomain, $license->domain_whitelist)) {
                LicenseActivation::create([
                    'license_id' => $license->id,
                    'ip_address' => $ip,
                    'domain' => $parsedDomain,
                    'action' => 'rejected',
                    'details' => "Domain {$parsedDomain} tidak ada dalam whitelist",
                ]);
                return response()->json([
                    'message' => "Domain {$parsedDomain} tidak diizinkan untuk license ini.",
                    'status' => 'domain_rejected',
                ], 403);
            }
        }

        // Activate!
        $user = $request->user();
        $ipLog = $license->ip_log ?? [];
        $ipLog[] = [
            'ip' => $ip,
            'domain' => $parsedDomain,
            'at' => now()->toISOString(),
            'action' => 'activated',
        ];

        $updateData = [
            'status' => 'active',
            'activation_count' => $license->activation_count + 1,
            'activated_at' => $license->activated_at ?? now(),
            'ip_log' => $ipLog,
        ];

        // If no org assigned yet, assign to current user's org
        if (!$license->org_id && $user) {
            $updateData['org_id'] = $user->org_id;
            $updateData['org_name'] = $user->organization->name ?? null;
        }

        // If domain whitelist is empty, auto-record the domain
        if (empty($license->domain_whitelist) || count($license->domain_whitelist) === 0) {
            $updateData['domain_whitelist'] = [$parsedDomain];
        }

        // For SaaS: set expiry from activation time
        if ($license->license_type === 'saas' && !$license->expires_at && $license->duration_days) {
            $updateData['expires_at'] = now()->addDays($license->duration_days);
        }

        $license->update($updateData);

        LicenseActivation::create([
            'license_id' => $license->id,
            'ip_address' => $ip,
            'domain' => $parsedDomain,
            'server_hostname' => gethostname(),
            'action' => 'activated',
            'details' => "License activated successfully for org: " . ($updateData['org_name'] ?? $license->org_name ?? 'unknown'),
        ]);

        return response()->json([
            'message' => 'License berhasil diaktifkan!',
            'data' => $license->fresh(),
            'status' => 'active',
        ]);
    }

    /**
     * Verify current org's license status (called on frontend load)
     */
    public function verify(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->org_id) {
            return response()->json(['licensed' => false, 'message' => 'No organization'], 200);
        }

        $license = License::where('org_id', $user->org_id)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$license) {
            return response()->json([
                'licensed' => false,
                'message' => 'Belum ada license aktif. Silahkan masukkan license key.',
            ]);
        }

        // Check expiry
        if ($license->isExpired()) {
            $license->update(['status' => 'expired']);
            return response()->json([
                'licensed' => false,
                'message' => 'License sudah expired. Silahkan perpanjang.',
                'expired_at' => $license->expires_at,
            ]);
        }

        // Log verify
        $ip = $request->ip();
        $domain = parse_url($request->header('Origin') ?? '', PHP_URL_HOST) ?? 'unknown';

        return response()->json([
            'licensed' => true,
            'license' => [
                'id' => $license->id,
                'key' => $license->license_key,
                'package_type' => $license->package_type,
                'license_type' => $license->license_type,
                'features' => $license->features,
                'expires_at' => $license->expires_at,
                'org_name' => $license->org_name,
            ],
        ]);
    }

    // =============================================
    // PRICING PLANS (SuperAdmin config)
    // =============================================

    public function pricingIndex()
    {
        $plans = PricingPlan::orderBy('sort_order')->get();
        return response()->json(['data' => $plans]);
    }

    public function pricingUpdate(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'plans' => 'required|array',
            'plans.*.package_type' => 'required|string',
            'plans.*.name' => 'required|string',
        ]);

        foreach ($request->plans as $planData) {
            PricingPlan::updateOrCreate(
                ['package_type' => $planData['package_type']],
                $planData
            );
        }

        return response()->json(['message' => 'Pricing updated']);
    }

    // =============================================
    // HELPERS
    // =============================================

    private function getPackageFeatures(string $packageType): array
    {
        return match ($packageType) {
            'basic' => [
                'ropa' => true, 'dpia' => true, 'consent' => true, 'breach' => true,
                'dsr' => true, 'gap_assessment' => true, 'simulation' => true,
                'data_mapping' => true, 'docs' => true,
                'ai_assistant' => false, 'ai_risk_scoring' => false,
                'ai_agent' => false, 'live_drill' => false,
            ],
            'ai' => [
                'ropa' => true, 'dpia' => true, 'consent' => true, 'breach' => true,
                'dsr' => true, 'gap_assessment' => true, 'simulation' => true,
                'data_mapping' => true, 'docs' => true,
                'ai_assistant' => true, 'ai_risk_scoring' => true,
                'ai_agent' => false, 'live_drill' => true,
            ],
            'ai_agent' => [
                'ropa' => true, 'dpia' => true, 'consent' => true, 'breach' => true,
                'dsr' => true, 'gap_assessment' => true, 'simulation' => true,
                'data_mapping' => true, 'docs' => true,
                'ai_assistant' => true, 'ai_risk_scoring' => true,
                'ai_agent' => true, 'live_drill' => true,
            ],
            default => [],
        };
    }
}
