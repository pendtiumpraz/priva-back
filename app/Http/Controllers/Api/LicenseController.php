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
    /**
     * Activate a license key (called by admin when inputting license)
     * Proxies to external License Manager
     */
    public function activate(Request $request)
    {
        $request->validate([
            'license_key' => 'required|string',
        ]);

        $user = $request->user();
        $ip = $request->ip();
        $domain = parse_url($request->header('Origin') ?? '', PHP_URL_HOST) ?? 'unknown';

        $lmUrl = env('LICENSE_MANAGER_URL', 'https://license-priva.sainskerta.net');

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withoutVerifying()
                ->post("{$lmUrl}/api/licenses/verify", [
                'license_key' => $request->license_key,
                'domain' => $domain,
                'ip' => $ip,
            ]);

            $data = $response->json();

            if ($response->ok() && ($data['valid'] ?? false)) {
                // Save license locally
                $licenseData = $data['license'] ?? [];
                $local = License::updateOrCreate(
                ['license_key' => $request->license_key],
                [
                    'package_type' => $licenseData['package_type'] ?? 'basic',
                    'license_type' => $licenseData['license_type'] ?? 'perpetual',
                    'status' => 'active',
                    'features' => $licenseData['features'] ?? [],
                    'org_name' => $licenseData['org_name'] ?? $user->organization->name ?? null,
                    'org_id' => $user->org_id ?? null,
                    'expires_at' => $licenseData['expires_at'] ?? null,
                    'activated_at' => $licenseData['activated_at'] ?? now(),
                    'activation_count' => 1,
                    'max_activations' => 1,
                    'ip_log' => [['ip' => $ip, 'domain' => $domain, 'at' => now()->toISOString()]],
                ]
                );

                return response()->json([
                    'message' => 'License berhasil diaktifkan!',
                    'data' => $local,
                    'status' => 'active',
                ]);
            }

            // Forward error from license manager
            return response()->json([
                'message' => $data['message'] ?? 'License tidak valid',
                'status' => $data['status'] ?? 'invalid',
            ], $response->status());

        }
        catch (\Exception $e) {
            \Log::error('License Manager connection error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal menghubungi License Manager. Coba lagi nanti.',
            ], 502);
        }
    }

    /**
     * Verify current org's license status (called on frontend load)
     * First checks local DB, then optionally re-validates with License Manager
     */
    public function verify(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['licensed' => false, 'message' => 'Not authenticated'], 200);
        }

        $license = null;

        if ($user->role === 'superadmin') {
            $license = License::where('status', 'active')
                ->where(function($q) use ($user) {
                    $q->whereNull('org_id')
                      ->orWhere('created_by', $user->id)
                      ->orWhere('org_id', $user->org_id);
                })
                ->orderBy('created_at', 'desc')
                ->first();
        } else {
            // admin, dpo, maker, viewer
            $license = License::where('org_id', $user->org_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->first();
        }

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
