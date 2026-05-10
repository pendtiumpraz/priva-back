<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginAttemptService;
use App\Services\PasswordPolicyService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginAttemptService $loginAttempts,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly TwoFactorAuthService $twoFactor,
    ) {}

    /**
     * Register a new user + organization.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|confirmed',
            'organization_name' => 'required|string|max:255',
        ]);

        // Password policy — separate dari format validation di atas supaya
        // pesan policy bisa di-map ke field 'password' dengan multiple messages.
        $violations = $this->passwordPolicy->validate($request->password, $request->email);
        if (! empty($violations)) {
            throw ValidationException::withMessages([
                'password' => array_column($violations, 'message'),
            ]);
        }

        // Create organization
        $org = \App\Models\Organization::create([
            'name' => $request->organization_name,
            'slug' => \Illuminate\Support\Str::slug($request->organization_name) . '-' . uniqid(),
            'org_level' => 'holding',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'org_id' => $org->id,
            'role' => 'admin',
        ]);

        $tenantRole = \App\Models\TenantRole::where('org_id', $org->id)->where('name', 'Admin')->first();
        if ($tenantRole) {
            $user->tenant_role_id = $tenantRole->id;
            $user->save();
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        // Notify superadmins — new tenant signup. Platform-level (org_id=null).
        try {
            \App\Services\NotificationService::dispatch(
                kind: 'info',
                severity: 'low',
                module: 'tenant',
                type: 'tenant.signup',
                recipient: 'role:superadmin',
                orgId: null,
                title: "🎉 Tenant baru: {$org->name}",
                body: "Admin: {$user->name} ({$user->email}) — slug: {$org->slug}",
                actionUrl: '/license',
                metadata: [
                    'org_id' => $org->id,
                    'org_name' => $org->name,
                    'admin_name' => $user->name,
                    'admin_email' => $user->email,
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('Tenant signup notif failed: ' . $e->getMessage());
        }

        return response()->json([
            'user' => $this->userWithPackageType($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login user.
     *
     * Lockout flow (configurable di /platform-admin/system-settings → Security):
     *   1. Cari user; kalau tidak ada → generic error (anti enumeration), TIDAK
     *      menaikkan counter manapun (mencegah attacker "lock" email random).
     *   2. Kalau user sedang ke-lock → return 423 + retry_after_seconds. Cegah
     *      attacker mengeksploitasi password check timing.
     *   3. Cek password; kalau salah → recordFailure() (counter naik, mungkin
     *      trigger lock baru) lalu generic error.
     *   4. Cek is_active; kalau nonaktif → generic message tanpa reset counter
     *      (akun dinonaktifkan tetap tidak boleh login).
     *   5. recordSuccess() reset counter + tulis last_login_at/ip.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok.'],
            ]);
        }

        // Lockout check sebelum password check — supaya password check tidak
        // jadi side-channel (attacker tetap bisa coba walau lock, hanya tahu
        // "lock". Tapi tanpa cek ini, dia juga bisa terus brute force.)
        if (($retryAfter = $this->loginAttempts->lockedRetryAfter($user)) !== null) {
            return response()->json([
                'message' => "Akun terkunci sementara. Coba lagi dalam {$retryAfter} detik.",
                'errors' => [
                    'email' => ["Akun terkunci sementara. Coba lagi dalam {$retryAfter} detik."],
                ],
                'locked' => true,
                'retry_after_seconds' => $retryAfter,
                'locked_until' => $user->locked_until?->toISOString(),
            ], 423);
        }

        if (! Hash::check($request->password, $user->password)) {
            $this->loginAttempts->recordFailure($user, $request->ip());

            // Sesudah recordFailure, mungkin user baru saja melewati threshold
            // dan langsung ke-lock. Sertakan info itu supaya UI bisa langsung
            // pasang countdown tanpa user harus retry sekali lagi.
            $user->refresh();
            $retryAfter = $this->loginAttempts->lockedRetryAfter($user);
            if ($retryAfter !== null) {
                return response()->json([
                    'message' => "Akun terkunci sementara. Coba lagi dalam {$retryAfter} detik.",
                    'errors' => [
                        'email' => ["Akun terkunci sementara. Coba lagi dalam {$retryAfter} detik."],
                    ],
                    'locked' => true,
                    'retry_after_seconds' => $retryAfter,
                    'locked_until' => $user->locked_until?->toISOString(),
                ], 423);
            }

            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda telah dinonaktifkan.'],
            ]);
        }

        // 2FA gate — kalau user sudah confirmed 2FA atau role-nya wajib 2FA,
        // jangan langsung issue full token. Issue challenge UUID yang user
        // pakai untuk POST /auth/2fa/verify dengan kode dari authenticator.
        if (config('security.2fa_enabled', true) && $this->twoFactor->isRequiredFor($user)) {
            // User sudah confirm 2FA → masuk flow verify
            if ($user->two_factor_confirmed_at) {
                $challenge = $this->twoFactor->issueChallenge($user);
                // recordSuccess di-defer ke /auth/2fa/verify supaya counter
                // failed_login_attempts tetap reset hanya kalau ENTIRE flow
                // (password + 2FA) sukses.
                return response()->json([
                    'requires_2fa' => true,
                    'challenge' => $challenge,
                    'message' => 'Masukkan kode 2FA dari authenticator.',
                ]);
            }
            // Role wajib 2FA tapi user belum setup → flag setup_required.
            // Frontend render setup wizard. User tetap tidak dapat token
            // sampai 2FA di-confirm.
            return response()->json([
                'requires_2fa_setup' => true,
                'message' => 'Akun Anda wajib mengaktifkan 2FA. Silakan setup terlebih dahulu.',
                // Issue temporary token dengan ability terbatas hanya untuk
                // 2FA setup endpoints. Akan di-revoke setelah confirm.
                'setup_token' => $user->createToken('2fa-setup', ['2fa:setup'])->plainTextToken,
            ]);
        }

        $this->loginAttempts->recordSuccess($user, $request->ip());

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $this->userWithPackageType($user),
            'token' => $token,
        ]);
    }

    /**
     * Verifikasi 2FA challenge — second step dari login.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'challenge' => 'required|string|uuid',
            'code' => 'required|string|max:32',
        ]);

        $user = $this->twoFactor->verifyChallenge($request->challenge, $request->code);
        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['Kode 2FA tidak valid atau challenge sudah kedaluwarsa.'],
            ]);
        }

        $this->loginAttempts->recordSuccess($user, $request->ip());

        return response()->json([
            'user' => $this->userWithPackageType($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
        ]);
    }

    /**
     * Setup 2FA — generate secret + QR. State = pending sampai confirm.
     */
    public function setupTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! config('security.2fa_enabled', true)) {
            return response()->json(['message' => '2FA fitur dimatikan oleh administrator.'], 403);
        }
        if ($user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA sudah aktif. Disable dulu sebelum re-setup.'], 422);
        }

        $issuer = config('app.name', 'Privasimu');
        $data = $this->twoFactor->setup($user, $issuer);

        return response()->json($data);
    }

    /**
     * Confirm 2FA setup dengan kode pertama dari authenticator. Return
     * recovery codes (sekali itu doang plaintext — user wajib save).
     */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();
        $recovery = $this->twoFactor->confirm($user, $request->code);
        if (! $recovery) {
            throw ValidationException::withMessages([
                'code' => ['Kode tidak valid. Coba lagi dengan kode terbaru dari authenticator.'],
            ]);
        }

        // Setelah confirm, revoke setup_token kalau ada — full token akan
        // di-issue saat next login flow.
        $current = $request->user()->currentAccessToken();
        if ($current && $current->name === '2fa-setup') {
            $current->delete();
        }

        return response()->json([
            'message' => '2FA berhasil diaktifkan. Simpan recovery codes di tempat aman.',
            'recovery_codes' => $recovery,
        ]);
    }

    /**
     * Disable 2FA. Wajib re-confirm password supaya gak ada attacker yang
     * curi session bisa langsung disable.
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password salah.'],
            ]);
        }

        $this->twoFactor->disable($user);
        return response()->json(['message' => '2FA dinonaktifkan.']);
    }

    /**
     * Regenerate recovery codes. Old codes invalidated.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password salah.'],
            ]);
        }

        if (! $user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA belum aktif.'], 422);
        }

        return response()->json([
            'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user),
        ]);
    }

    /**
     * Status 2FA user — dipakai frontend profile page.
     */
    public function twoFactorStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'enabled' => (bool) $user->two_factor_confirmed_at,
            'pending_setup' => (bool) $user->two_factor_secret && ! $user->two_factor_confirmed_at,
            'required_by_policy' => $this->twoFactor->isRequiredFor($user) && ! $user->two_factor_confirmed_at,
            'recovery_codes_remaining' => $this->twoFactor->recoveryCodesCount($user),
            'feature_enabled' => (bool) config('security.2fa_enabled', true),
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userWithPackageType($request->user()),
        ]);
    }

    /**
     * Public — return active password policy supaya UI register / change-password
     * bisa render checklist live. Tidak include daftar common-passwords (300+
     * entries) untuk hemat payload; common-check tetap ditegakkan server-side.
     */
    public function passwordPolicy(): JsonResponse
    {
        return response()->json([
            'policy' => $this->passwordPolicy->getPolicy(),
        ]);
    }

    /**
     * Serialize a user with eager-loaded relations and the trusted license
     * package_type attached for frontend feature gating. Used by both login
     * and me so the frontend sees the same shape on first render — otherwise
     * the user has to reload once before AI features appear.
     */
    private function userWithPackageType(User $user): array
    {
        $user->load('organization', 'tenantRole');

        $packageType = null;
        if ($user->org_id) {
            $license = \App\Models\License::where('org_id', $user->org_id)
                ->where('status', 'active')
                ->first();
            if ($license && $license->isActive()) {
                $packageType = $license->getTrustedPackageType();
            }
        } elseif (in_array($user->role, ['root','superadmin'], true)) {
            $packageType = 'ai_agent';
        }

        $userData = $user->toArray();
        $userData['package_type'] = $packageType;
        return $userData;
    }

    /**
     * Logout (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Berhasil logout.',
        ]);
    }

    /**
     * Update user-specific settings (e.g. idle timeout for SA).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $fields = $request->validate([
            'idle_timeout_enabled' => 'nullable|boolean',
            'idle_timeout_minutes' => 'nullable|integer|min:1',
        ]);

        $settings = $user->settings ?? [];
        if (isset($fields['idle_timeout_enabled'])) $settings['idle_timeout_enabled'] = (bool) $fields['idle_timeout_enabled'];
        if (isset($fields['idle_timeout_minutes'])) $settings['idle_timeout_minutes'] = (int) $fields['idle_timeout_minutes'];

        $user->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Settings updated successfully.',
            'user' => $user->load('organization'),
            'settings' => $settings,
        ]);
    }
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $fields = $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'locale' => 'nullable|string|in:id,en',
        ]);

        $user->update($fields);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->load('organization'),
        ]);
    }
}
