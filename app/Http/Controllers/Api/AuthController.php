<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\IpAllowlistService;
use App\Services\LoginAttemptService;
use App\Services\PasswordPolicyService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginAttemptService $loginAttempts,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly TwoFactorAuthService $twoFactor,
        private readonly IpAllowlistService $ipAllowlist,
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
            'password_changed_at' => now(),
        ]);

        $tenantRole = \App\Models\TenantRole::where('org_id', $org->id)->where('name', 'Admin')->first();
        if ($tenantRole) {
            $user->tenant_role_id = $tenantRole->id;
            $user->save();
        }

        // Email verification — kalau setting required, kirim verification
        // notification dan JANGAN issue token sampai user verify. Frontend
        // akan render halaman "check your email".
        $verificationRequired = (bool) config('security.email_verification_required', false);
        if ($verificationRequired) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                \Log::warning('Gagal kirim email verification: '.$e->getMessage());
            }
            return response()->json([
                'user' => $this->userWithPackageType($user),
                'requires_email_verification' => true,
                'message' => 'Akun berhasil dibuat. Silakan periksa email Anda untuk menyelesaikan verifikasi.',
            ], 201);
        }

        // Kalau tidak required, set verified_at = now supaya gak nyangkut
        // di unverified state.
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
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

        // IP allowlist gate — root/superadmin only. Kalau dipaksa enforce
        // tapi IP request bukan di list, block dengan generic message
        // (gak bocor info bahwa email/password yang benar).
        if (! $this->ipAllowlist->isAllowed($user->role ?? '', $request->ip())) {
            \Log::warning('Login blocked by IP allowlist', [
                'user_id' => $user->id, 'role' => $user->role, 'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Akses dari IP ini tidak diizinkan untuk role Anda. Hubungi administrator.'],
            ]);
        }

        // Email verification gate — password OK tapi belum verify email.
        // Block login dengan 403 + flag supaya frontend render "check email" page.
        if ((bool) config('security.email_verification_required', false) && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email Anda belum diverifikasi. Silakan periksa inbox email Anda atau ajukan permintaan link verifikasi baru.',
                'requires_email_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        // Password rotation gate — kalau setting aktif dan password sudah
        // melewati rotation policy, block login + flag UI render "change password".
        if ($this->passwordPolicy->needsRotation($user)) {
            return response()->json([
                'message' => 'Password Anda perlu diperbarui sesuai dengan kebijakan rotasi password yang berlaku. Silakan hubungi administrator.',
                'requires_password_rotation' => true,
                'days_since_change' => $user->password_changed_at?->diffInDays(now()),
            ], 403);
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
                    'message' => 'Silakan masukkan kode 2FA dari authenticator app Anda.',
                ]);
            }
            // Role wajib 2FA tapi user belum setup → flag setup_required.
            // Frontend render setup wizard. User tetap tidak dapat token
            // sampai 2FA di-confirm.
            return response()->json([
                'requires_2fa_setup' => true,
                'message' => 'Akun Anda wajib mengaktifkan 2FA. Silakan melakukan setup terlebih dahulu sebelum mengakses platform.',
                // Issue temporary token dengan ability terbatas hanya untuk
                // 2FA setup endpoints. Akan di-revoke setelah confirm.
                'setup_token' => $user->createToken('2fa-setup', ['2fa:setup'])->plainTextToken,
            ]);
        }

        $this->loginAttempts->recordSuccess($user, $request->ip());

        $this->enforceSessionLimit($user);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $this->userWithPackageType($user),
            'token' => $token,
        ]);
    }

    /**
     * Concurrent session limit — sebelum issue token baru, kalau user sudah
     * punya >= max sessions, hapus token lama (FIFO) supaya total tetap di
     * limit. Setting = 0 → disabled (unlimited).
     */
    private function enforceSessionLimit(User $user): void
    {
        $max = (int) config('security.max_sessions_per_user', 0);
        if ($max <= 0) return;

        $tokens = $user->tokens()
            ->where('name', 'auth-token') // hanya count login tokens (skip 2fa-setup dll)
            ->orderBy('created_at', 'asc')
            ->get();

        $deleteCount = $tokens->count() - ($max - 1); // -1 karena akan issue baru
        if ($deleteCount <= 0) return;

        $tokens->take($deleteCount)->each->delete();
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
                'code' => ['Kode 2FA tidak valid atau sesi verifikasi telah kedaluwarsa. Silakan login ulang.'],
            ]);
        }

        $this->loginAttempts->recordSuccess($user, $request->ip());

        $this->enforceSessionLimit($user);

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
            return response()->json(['message' => 'Fitur 2FA telah dinonaktifkan oleh administrator.'], 403);
        }
        if ($user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA sudah aktif. Silakan nonaktifkan terlebih dahulu sebelum melakukan setup ulang.'], 422);
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
                'code' => ['Kode tidak valid. Silakan coba lagi dengan kode terbaru dari authenticator app.'],
            ]);
        }

        // Setelah confirm, revoke setup_token kalau ada — full token akan
        // di-issue saat next login flow.
        $current = $request->user()->currentAccessToken();
        if ($current && $current->name === '2fa-setup') {
            $current->delete();
        }

        return response()->json([
            'message' => '2FA berhasil diaktifkan. Mohon simpan recovery codes di tempat yang aman.',
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
                'password' => ['Password yang Anda masukkan tidak benar.'],
            ]);
        }

        $this->twoFactor->disable($user);
        return response()->json(['message' => '2FA berhasil dinonaktifkan.']);
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
                'password' => ['Password yang Anda masukkan tidak benar.'],
            ]);
        }

        if (! $user->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA belum aktif untuk akun ini.'], 422);
        }

        return response()->json([
            'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user),
        ]);
    }

    /**
     * Verify email via signed link dari email notification.
     * Laravel auto-generate signed URL pakai user.id + sha1(email).
     */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        // Validate signed hash — sha1 dari email user (sesuai Laravel default).
        if (! hash_equals((string) sha1($user->getEmailForVerification()), (string) $hash)) {
            return response()->json(['message' => 'Link verifikasi tidak valid.'], 403);
        }

        // Validate signed URL signature (URL::signedRoute → temporarySignedRoute)
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Link verifikasi kedaluwarsa atau invalid.'], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // Redirect ke frontend halaman success — kalau dipanggil dari email
        // browser, ideal-nya redirect ke /login dengan flash message. Untuk
        // API-only behavior, return JSON.
        $redirectTo = $request->query('redirect_to');
        if ($redirectTo && str_starts_with($redirectTo, config('app.frontend_url', 'http://localhost:3000'))) {
            return redirect()->away($redirectTo.'?verified=1');
        }

        return response()->json([
            'message' => 'Email berhasil diverifikasi. Silakan login.',
            'verified' => true,
        ]);
    }

    /**
     * Resend verification email. Public endpoint, throttled di route.
     */
    public function resendEmailVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        // Generic response — JANGAN bocor apakah email exist atau sudah verified.
        if ($user && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                \Log::warning('Resend verification failed: '.$e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Apabila email tersebut terdaftar dan belum diverifikasi, link verifikasi telah dikirim.',
        ]);
    }

    /**
     * Forgot password — kirim link reset ke email user.
     *
     * Pakai Laravel Password broker (config/auth.php): token disimpan di
     * password_reset_tokens table, expired 60 menit, throttled 60 detik
     * antar request (broker level).
     *
     * Response selalu generic — JANGAN bocor apakah email terdaftar
     * (enumeration attack prevention). User dengan akun terkunci /
     * soft-deleted juga tidak kebagian token (Password broker handle).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::broker()->sendResetLink(
            ['email' => $request->email]
        );

        // Log untuk audit — tidak bocor apakah email match atau tidak
        \Log::info('Password reset requested', [
            'email_hash' => hash('sha256', strtolower(trim($request->email))),
            'status' => $status,
            'ip' => $request->ip(),
        ]);

        // Generic response — jangan bocor email enumeration
        return response()->json([
            'message' => 'Apabila email tersebut terdaftar, link reset password telah dikirim. Periksa kotak masuk Anda.',
        ]);
    }

    /**
     * Reset password — terima token + email + password baru, validate, set.
     *
     * Token sumber: email link yang dikirim forgotPassword(). Expired 60
     * menit (config/auth.php passwords.users.expire). Setelah berhasil,
     * token di-delete oleh Password broker.
     *
     * Password policy: enforce via PasswordPolicyService biar konsisten
     * dengan register/change-password (history check, length, character set,
     * common password blocklist).
     *
     * Side-effect: revoke semua Sanctum token user supaya semua sesi lama
     * logged out (security best practice setelah password change).
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|confirmed|min:8',
        ]);

        // Password policy — separate dari format validation
        $violations = $this->passwordPolicy->validate($request->password, $request->email);
        if (! empty($violations)) {
            throw ValidationException::withMessages([
                'password' => array_column($violations, 'message'),
            ]);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password' => $password, // hashed by User cast / mutator
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                ])->save();

                // Revoke semua Sanctum token — force logout all sessions
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }

                \Log::info('Password reset successful', [
                    'user_id' => $user->id,
                    'ip' => $request->ip(),
                ]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Token tidak valid / expired / user tidak ada — pesan generic
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return response()->json([
            'message' => 'Password berhasil direset. Silakan login dengan password baru Anda.',
            'reset' => true,
        ]);
    }

    /**
     * Verify password user current — re-auth untuk operation sensitif
     * tanpa harus logout-relogin. Pattern industry: GitHub "Confirm password
     * to access settings", AWS "MFA required for billing", dll.
     *
     * Dipakai untuk action yang lock-yourself-out risk seperti:
     *   - Toggle IP allowlist enforcement
     *   - Disable 2FA (already enforced di disableTwoFactor)
     *   - Force password rotation OFF (future)
     *
     * Return 200 kalau benar, 401 + ValidationException kalau salah.
     * Throttle:api 60/menit global ditambah sudah cukup anti brute force.
     */
    public function verifyPassword(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password yang Anda masukkan tidak benar.'],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * "What's my IP?" helper untuk PlatformTab di SecuritySection.
     * Admin sering bingung kalau IP-nya berubah-ubah (wifi/cellular/VPN) —
     * endpoint ini ngebantu cari nilai yang harus dimasukkan ke allowlist.
     *
     * Return juga role supaya UI tau tombol "add to which allowlist".
     */
    public function whoamiIp(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'ip' => $request->ip(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'real_ip' => $request->header('X-Real-IP'),
            'role' => $user?->role,
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
     * Update user-specific settings (e.g. idle timeout for SA, AI toggle).
     *
     * Settings disimpan di kolom JSON `users.settings`. Merge-patch — field
     * yang tidak dikirim tidak ter-overwrite.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $fields = $request->validate([
            'idle_timeout_enabled' => 'nullable|boolean',
            'idle_timeout_minutes' => 'nullable|integer|min:1',
            // Per-user AI toggle. Saat false: FE hide semua AI affordance
            // (sidebar items, tombol AI, modal intent skip ke manual). Backend
            // endpoint AI tetap aktif (toggle adalah UI preference, bukan
            // policy — user mungkin re-enable sebentar lagi).
            'ai_enabled' => 'nullable|boolean',
            // Show AI token usage tooltip di /chat-history Master AI Audit
            // page. Default false (hide). Toggle untuk superadmin yang audit
            // cost analysis per conversation. Per-user preference, sync via DB.
            'show_ai_tokens' => 'nullable|boolean',
        ]);

        $settings = $user->settings ?? [];
        if (isset($fields['idle_timeout_enabled'])) $settings['idle_timeout_enabled'] = (bool) $fields['idle_timeout_enabled'];
        if (isset($fields['idle_timeout_minutes'])) $settings['idle_timeout_minutes'] = (int) $fields['idle_timeout_minutes'];
        if (array_key_exists('ai_enabled', $fields)) $settings['ai_enabled'] = (bool) $fields['ai_enabled'];
        if (array_key_exists('show_ai_tokens', $fields)) $settings['show_ai_tokens'] = (bool) $fields['show_ai_tokens'];

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
