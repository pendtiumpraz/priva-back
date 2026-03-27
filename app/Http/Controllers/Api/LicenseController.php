<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
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

    /**
     * Assign a license key to a tenant.
     * SA is trusted — try LM verification first, fallback to direct assignment.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'license_key' => 'required|string',
            'org_id' => 'required|uuid',
            'package_type' => 'nullable|in:basic,ai,ai_agent',
            'license_type' => 'nullable|in:perpetual,saas',
            'duration_days' => 'nullable|integer|min:1',
        ]);

        $org = Organization::findOrFail($request->org_id);
        $domain = $request->getHost();
        $ip = $request->ip();

        // Try to verify with License Manager
        $lmUrl = env('LICENSE_MANAGER_URL', 'https://license-priva.sainskerta.net');
        $licenseData = null;

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withoutVerifying()
                ->post("{$lmUrl}/api/licenses/verify", [
                'license_key' => $request->license_key,
                'domain' => $domain,
                'ip' => $ip,
            ]);

            $data = $response->json();
            if ($response->ok() && ($data['valid'] ?? false)) {
                $licenseData = $data['license'] ?? [];
            }
        } catch (\Exception $e) {
            \Log::warning('LM unreachable for store(), using SA-provided data', [
                'error' => $e->getMessage(),
            ]);
        }

        // Use LM data if available, otherwise use SA-provided values
        $packageType = $licenseData['package_type'] ?? $request->package_type ?? 'basic';
        $licenseType = $licenseData['license_type'] ?? $request->license_type ?? 'saas';
        $expiresAt = $licenseData['expires_at'] ?? null;
        $maxActivations = $licenseData['max_activations'] ?? 2;
        $features = $licenseData['features'] ?? $this->getPackageFeatures($packageType);

        // If no LM data and SA provided duration, calculate expiry
        if (!$expiresAt && $licenseType === 'saas' && $request->duration_days) {
            $expiresAt = now()->addDays($request->duration_days);
        }

        // Save locally & assign to tenant
        $local = License::updateOrCreate(
            ['license_key' => $request->license_key, 'org_id' => $org->id],
            [
                'package_type' => $packageType,
                'license_type' => $licenseType,
                'status' => 'active',
                'features' => $features,
                'org_name' => $org->name,
                'expires_at' => $expiresAt,
                'activated_at' => now(),
                'activation_count' => 1,
                'max_activations' => $maxActivations,
                'created_by' => $user->id,
                'ip_log' => [['ip' => $ip, 'domain' => $domain, 'at' => now()->toISOString(),
                    'source' => $licenseData ? 'license_manager' : 'sa_direct']],
            ]
        );

        // Auto-set AI credits
        $credits = match ($packageType) {
            'ai'       => 100,
            'ai_agent' => 500,
            default    => 0,
        };
        $org->update([
            'ai_credits_monthly' => $credits,
            'ai_credits_remaining' => $credits,
            'ai_credits_reset_at' => now()->addMonth(),
        ]);

        $source = $licenseData ? '(verified via License Manager)' : '(direct assignment)';
        return response()->json([
            'message' => "License berhasil di-assign ke {$org->name} {$source}",
            'data' => $local,
        ], 201);
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
                $licenseData = $data['license'] ?? [];

                // Check existing active license for this tenant — accumulate remaining time
                $newExpiresAt = $licenseData['expires_at'] ?? null;

                if ($user->org_id && $newExpiresAt) {
                    $existingLicense = License::where('org_id', $user->org_id)
                        ->where('status', 'active')
                        ->where('license_type', 'saas')
                        ->where('license_key', '!=', $request->license_key) // Don't count same key
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', now())
                        ->orderBy('expires_at', 'desc')
                        ->first();

                    if ($existingLicense) {
                        // Calculate remaining days from old license
                        $remainingDays = (int) now()->diffInDays($existingLicense->expires_at, false);
                        if ($remainingDays > 0) {
                            // New expiry = new expires_at + remaining days from old license
                            $newExpiresAt = \Carbon\Carbon::parse($newExpiresAt)->addDays($remainingDays);
                        }
                        // Mark old license as superseded
                        $existingLicense->update(['status' => 'superseded']);
                    }
                }

                // Save license locally (match by key + org so same key can serve SA + tenant)
                $local = License::updateOrCreate(
                ['license_key' => $request->license_key, 'org_id' => $user->org_id ?? null],
                [
                    'package_type' => $licenseData['package_type'] ?? 'basic',
                    'license_type' => $licenseData['license_type'] ?? 'perpetual',
                    'status' => 'active',
                    'features' => $licenseData['features'] ?? [],
                    'org_name' => $licenseData['org_name'] ?? $user->organization->name ?? null,
                    'expires_at' => $newExpiresAt,
                    'activated_at' => $licenseData['activated_at'] ?? now(),
                    'activation_count' => 1,
                    'max_activations' => $licenseData['max_activations'] ?? 1,
                    'ip_log' => [['ip' => $ip, 'domain' => $domain, 'at' => now()->toISOString()]],
                ]
                );

                // Auto-set AI credits based on package type
                if ($user->org_id) {
                    $credits = match ($licenseData['package_type'] ?? 'basic') {
                        'ai'       => 100,
                        'ai_agent' => 500,
                        default    => 0,
                    };
                    Organization::where('id', $user->org_id)->update([
                        'ai_credits_monthly' => $credits,
                        'ai_credits_remaining' => $credits,
                        'ai_credits_reset_at' => now()->addMonth(),
                    ]);
                }

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
            \Log::error('License Manager connection error', [
                'url' => ($lmUrl ?? 'unknown') . '/api/licenses/verify',
                'license_key' => $request->license_key,
                'error' => $e->getMessage(),
            ]);

            // FALLBACK: If License Manager unreachable, check if key already exists locally
            try {
                $existingKey = License::where('license_key', $request->license_key)
                    ->where('status', 'active')
                    ->first();

                if ($existingKey) {
                    // Check if THIS org already has this key activated
                    $alreadyActivated = License::where('license_key', $request->license_key)
                        ->where('org_id', $user->org_id)
                        ->where('status', 'active')
                        ->exists();

                    if ($alreadyActivated) {
                        return response()->json([
                            'message' => 'License key ini sudah aktif untuk tenant Anda.',
                        ], 422);
                    }

                    // Get org name safely
                    $orgName = null;
                    if ($user->org_id) {
                        $org = Organization::find($user->org_id);
                        $orgName = $org?->name;
                    }

                    // Key exists & is valid locally — activate for this tenant
                    $local = License::updateOrCreate(
                        ['license_key' => $request->license_key, 'org_id' => $user->org_id ?? null],
                        [
                            'package_type' => $existingKey->package_type,
                            'license_type' => $existingKey->license_type,
                            'status' => 'active',
                            'features' => $existingKey->features,
                            'org_name' => $orgName,
                            'expires_at' => $existingKey->expires_at,
                            'activated_at' => now(),
                            'activation_count' => ($existingKey->activation_count ?? 0) + 1,
                            'max_activations' => $existingKey->max_activations,
                            'ip_log' => [['ip' => $ip, 'domain' => $domain, 'at' => now()->toISOString(), 'mode' => 'offline']],
                        ]
                    );

                    // Set AI credits
                    if ($user->org_id) {
                        $credits = match ($existingKey->package_type) {
                            'ai'       => 100,
                            'ai_agent' => 500,
                            default    => 0,
                        };
                        Organization::where('id', $user->org_id)->update([
                            'ai_credits_monthly' => $credits,
                            'ai_credits_remaining' => $credits,
                            'ai_credits_reset_at' => now()->addMonth(),
                        ]);
                    }

                    return response()->json([
                        'message' => 'License berhasil diaktifkan!',
                        'data' => $local,
                        'status' => 'active',
                    ]);
                }
            } catch (\Exception $fallbackError) {
                \Log::error('License offline fallback error', [
                    'error' => $fallbackError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Gagal menghubungi License Manager dan key tidak ditemukan di database lokal.',
                'debug' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
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
            // SuperAdmin: check for ANY platform license (org_id = NULL)
            // All super admins share ONE platform license
            $license = License::where('status', 'active')
                ->whereNull('org_id')
                ->orderBy('created_at', 'desc')
                ->first();
        } else {
            // All tenant roles (admin, dpo, maker, viewer):
            // check their TENANT's license by org_id
            // If tenant has no license, ALL roles in that tenant are locked
            if (!$user->org_id) {
                return response()->json([
                    'licensed' => false,
                    'message' => 'Akun tidak terhubung ke organisasi.',
                ]);
            }
            $license = License::where('org_id', $user->org_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$license) {
            $msg = $user->role === 'superadmin'
                ? 'Belum ada platform license. Silahkan aktivasi license.'
                : 'Tenant ini belum memiliki license aktif. Hubungi administrator.';
            return response()->json([
                'licensed' => false,
                'message' => $msg,
                'role' => $user->role,
            ]);
        }

        // Check expiry (SaaS)
        if ($license->isExpired()) {
            $license->update(['status' => 'expired']);
            return response()->json([
                'licensed' => false,
                'message' => 'License sudah expired. Silahkan perpanjang.',
                'expired_at' => $license->expires_at,
                'role' => $user->role,
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
            'role' => $user->role,
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
