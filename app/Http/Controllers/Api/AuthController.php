<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user + organization.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'organization_name' => 'required|string|max:255',
        ]);

        // Create organization
        $org = \App\Models\Organization::create([
            'name' => $request->organization_name,
            'slug' => \Illuminate\Support\Str::slug($request->organization_name) . '-' . uniqid(),
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

        return response()->json([
            'user' => $user->load('organization'),
            'token' => $token,
        ], 201);
    }

    /**
     * Login user.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda telah dinonaktifkan.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('organization', 'tenantRole'),
            'token' => $token,
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('organization', 'tenantRole');

        // Attach active license package_type for frontend feature gating
        $packageType = null;
        if ($user->org_id) {
            $license = \App\Models\License::where('org_id', $user->org_id)
                ->where('status', 'active')
                ->first();
            $packageType = $license?->package_type;
        } elseif ($user->role === 'superadmin') {
            $packageType = 'ai_agent'; // SA always has full access
        }

        $userData = $user->toArray();
        $userData['package_type'] = $packageType;

        return response()->json([
            'user' => $userData,
        ]);
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
}
