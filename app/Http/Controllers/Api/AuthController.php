<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginAttemptService;
use App\Services\PasswordPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginAttemptService $loginAttempts,
        private readonly PasswordPolicyService $passwordPolicy,
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

        $this->loginAttempts->recordSuccess($user, $request->ip());

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $this->userWithPackageType($user),
            'token' => $token,
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
