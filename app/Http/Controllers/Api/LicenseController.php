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
                'tenant_id' => $org->id,
            ]);

            $data = $response->json();
            
            // If LM is reachable but explicitly rejects the license (e.g., 403 Forbidden, max activations exceeded)
            if ($response->failed() && isset($data['message'])) {
                return response()->json([
                    'message' => 'License Manager menolak aktivasi: ' . $data['message'],
                    'status' => $data['status'] ?? 'rejected'
                ], 403);
            }

            if ($response->ok() && ($data['valid'] ?? false)) {
                $licenseData = $data['license'] ?? [];
            } elseif (!$response->ok()) {
                 // Other non-2xx status code without clear message
                 throw new \Exception('License Manager returned HTTP ' . $response->status());
            }
        } catch (\Exception $e) {
            \Log::warning('LM unreachable/error for store(), using SA-provided data', [
                'error' => $e->getMessage(),
            ]);
            // If SA tries to activate without LM connection, but didn't provide package_type, fail.
            if (!$request->package_type) {
                return response()->json([
                    'message' => 'License Manager tidak dapat dihubungi, dan package_type tidak diisi untuk fallback offline.',
                ], 400);
            }
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

        // --- ENFORCE MAX ACTIVATION ACROSS TENANTS LOCALLY ---
        // Verify how many other orgs locally are already using this key
        $currentlyUsedBy = License::where('license_key', $request->license_key)
            ->whereNotNull('org_id')
            ->where('org_id', '!=', $org->id)
            ->where('status', 'active')
            ->count();

        // Plus check if superadmin platform license is using it (org_id is null)
        $isUsedByPlatform = License::where('license_key', $request->license_key)
            ->whereNull('org_id')
            ->where('status', 'active')
            ->exists() ? 1 : 0;
            
        $totalLocalUsage = $currentlyUsedBy + $isUsedByPlatform;

        if ($totalLocalUsage >= $maxActivations) {
            return response()->json([
                'message' => "Gagal: License ini memiliki limit $maxActivations aktivasi, dan sudah digunakan penuh oleh tenant/platform lain.",
                'status' => 'exceeded'
            ], 403);
        }

        // Save locally & assign to tenant
        // Use the signed payload from License Manager
        $signedPayload = $licenseData['signed_payload'] ?? null;

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
                'signed_payload' => $signedPayload,
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

    public function revoke(Request $request, string $id)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'password' => 'required|string',
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password salah! Tindakan dibatalkan.'], 403);
        }

        $license = License::findOrFail($id);
        $license->update(['status' => 'revoked']);

        // Since it's a SaaS platform, we might optionally want to communicate this back to License Manager
        // But local revocation is the most important part to cut access immediately.

        return response()->json(['message' => "License {$license->license_key} berhasil dicabut."]);
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
                'tenant_id' => $user->org_id ?? 'platform',
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

                // --- ENFORCE MAX ACTIVATION ACROSS TENANTS LOCALLY ---
                $maxActivations = $licenseData['max_activations'] ?? 1;
                $targetOrgId = $user->org_id ?? null;

                $currentlyUsedBy = License::where('license_key', $request->license_key)
                    ->where(function($q) use ($targetOrgId) {
                        if ($targetOrgId) {
                            $q->where('org_id', '!=', $targetOrgId)->whereNotNull('org_id');
                        } else {
                            $q->whereNotNull('org_id');
                        }
                    })
                    ->where('status', 'active')
                    ->count();

                $isUsedByPlatform = License::where('license_key', $request->license_key)
                    ->whereNull('org_id')
                    ->where(function($q) use ($targetOrgId) {
                        if (!$targetOrgId) {
                            // Being activated FOR platform right now, so we exclude the same exact context
                            $q->where('id', '<', 0); // Always false, just to skip
                        }
                    })
                    ->where('status', 'active')
                    ->exists() ? 1 : 0;
                    
                $totalLocalUsage = $currentlyUsedBy + $isUsedByPlatform;

                if ($totalLocalUsage >= $maxActivations) {
                    return response()->json([
                        'message' => "License sudah mencapai kuota aktivasi maksimal ($maxActivations) dan sedang dipakai oleh tenant/platform lain.",
                        'status' => 'exceeded'
                    ], 403);
                }

                // Save license locally (match by key + org so same key can serve SA + tenant)
                // Use the signed payload from License Manager
                $signedPayload = $licenseData['signed_payload'] ?? null;

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
                    'signed_payload' => $signedPayload,
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

            // NO OFFLINE FALLBACK — License Manager verification is MANDATORY
            // This prevents users from bypassing LM by editing the local database
            return response()->json([
                'message' => 'Gagal menghubungi License Manager. Aktivasi memerlukan koneksi ke server lisensi.',
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

        // Check expiry (SaaS) — uses signed payload for trusted expiry
        if ($license->isExpired()) {
            $license->update(['status' => 'expired']);
            return response()->json([
                'licensed' => false,
                'message' => 'License sudah expired. Silahkan perpanjang.',
                'expired_at' => $license->getTrustedExpiresAt(),
                'role' => $user->role,
            ]);
        }

        // Check signature integrity
        if ($license->signed_payload && !$license->isSignatureValid()) {
            $license->update(['status' => 'tampered']);
            \Log::critical('LICENSE TAMPERED DETECTED', [
                'license_id' => $license->id,
                'license_key' => $license->license_key,
                'org_id' => $license->org_id,
            ]);
            return response()->json([
                'licensed' => false,
                'message' => 'License tidak valid — terdeteksi modifikasi ilegal. Hubungi administrator.',
                'role' => $user->role,
            ]);
        }

        return response()->json([
            'licensed' => true,
            'license' => [
                'id' => $license->id,
                'key' => $license->license_key,
                'package_type' => $license->getTrustedPackageType(),
                'license_type' => $license->license_type,
                'features' => $license->features,
                'expires_at' => $license->getTrustedExpiresAt(),
                'org_name' => $license->org_name,
                'signature_valid' => $license->isSignatureValid(),
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
