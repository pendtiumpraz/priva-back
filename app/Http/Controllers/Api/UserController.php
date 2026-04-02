<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Check dynamic tenant_role permission.
     * Returns 403 response if denied, null if allowed.
     */
    private function checkPerm(Request $request, string $action = 'read')
    {
        $user = $request->user();
        if (!$user || $user->role === 'superadmin') return null;

        if (!$user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }

        $permissions = $user->tenantRole?->permissions ?? null;

        if (!is_array($permissions)) {
            // Legacy fallback
            if ($action === 'write' && !in_array($user->role, ['admin', 'dpo', 'maker'])) {
                return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.'], 403);
            }
            return null;
        }

        if (in_array('*', $permissions)) return null;

        if ($action === 'write') {
            if (!in_array('users:write', $permissions)) {
                return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk User Management.'], 403);
            }
            return null;
        }

        if (in_array('users', $permissions) || in_array('users:read', $permissions) || in_array('users:write', $permissions)) {
            return null;
        }

        return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin untuk User Management.'], 403);
    }
    /**
     * List users.
     * - superadmin: sees ALL users across ALL tenants (+ optional org_id filter)
     * - admin: sees only users within their own org
     */
    public function index(Request $request)
    {
        if ($denied = $this->checkPerm($request, 'read')) return $denied;
        $auth = $request->user();
        $query = User::with(['organization', 'tenantRole']);

        if ($auth->role === 'superadmin') {
            // Superadmin can filter by org_id optionally
            if ($request->filled('org_id')) {
                $query->where('org_id', $request->org_id);
            }
        } else {
            // Everyone else only sees their own org
            $query->where('org_id', $auth->org_id);
        }

        // Search
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('role', 'like', "%{$s}%");
            });
        }

        // Role filter
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Include trashed
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }

        $users = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $users]);
    }

    /**
     * Create a new user.
     */
    public function store(Request $request)
    {
        if ($denied = $this->checkPerm($request, 'write')) return $denied;
        $auth = $request->user();

        // Only superadmin and admin can create users
        if (!in_array($auth->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['sometimes', 'nullable'], // Keep as optional legacy fallback
            'tenant_role_id' => 'required|exists:tenant_roles,id',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
        ];

        // Role restriction modification
        if ($auth->role === 'superadmin') {
            $rules['role'] = ['sometimes'];
            $rules['tenant_role_id'] = ['nullable', 'exists:tenant_roles,id'];
            
            // If superadmin creates dpo/maker/viewer, org_id is required
            if (in_array($request->input('role'), ['dpo', 'maker', 'viewer'])) {
                $rules['org_id'] = 'required|exists:organizations,id';
            } else {
                // If superadmin or admin, org_id is not required
                $rules['org_id'] = 'nullable|exists:organizations,id';
            }
        } else {
            // Admin users will automatically get the org_id from their parent
        }

        $validated = $request->validate($rules);
        $validated['password'] = Hash::make($validated['password']);

        // Admin can only create users in their own org
        if ($auth->role !== 'superadmin') {
            $validated['org_id'] = $auth->org_id;
        }

        // Auto-create Organization if superadmin creates an 'admin' with org creation data
        if ($auth->role === 'superadmin' && $validated['role'] === 'admin' && empty($validated['org_id'])) {
            $orgName = $request->input('org_name', $validated['name'] . "'s Organization");
            $orgSlug = $request->input('org_slug', \Illuminate\Support\Str::slug($orgName . '-' . uniqid()));
            $orgIndustry = $request->input('org_industry', 'Other');
            $orgLevel = $request->input('org_level', 'holding');
            $parentId = $request->input('parent_id');
            
            $org = \App\Models\Organization::create([
                'name' => $orgName,
                'slug' => $orgSlug,
                'industry' => $orgIndustry,
                'org_level' => $orgLevel,
                'parent_id' => $parentId ?: null,
            ]);
            $validated['org_id'] = $org->id;

            // Create default system roles for the new org
            $allModules = ['dashboard', 'gap_assessment', 'ropa', 'dpia', 'data_discovery', 'contract_review', 'dsr', 'consent', 'breach', 'simulation', 'users', 'settings'];
            $allWrite = []; $allRead = [];
            foreach ($allModules as $mod) { $allWrite[] = "$mod:read"; $allWrite[] = "$mod:write"; $allRead[] = "$mod:read"; }

            $adminRole = \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'Admin', 'is_system' => true, 'description' => 'Administrator dengan full akses konfigurasi', 'permissions' => ['*']]);
            \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'DPO', 'is_system' => true, 'description' => 'Data Protection Officer', 'permissions' => $allWrite]);
            \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'Maker', 'is_system' => true, 'description' => 'User operasional yang input data', 'permissions' => array_filter($allWrite, fn($p) => !str_contains($p, 'users') && !str_contains($p, 'settings'))]);
            \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'Viewer', 'is_system' => true, 'description' => 'Akses hanya baca (read-only)', 'permissions' => $allRead]);
            
            $validated['tenant_role_id'] = $adminRole->id;
        }

        $validated['is_active'] = true;

        if (!isset($validated['role']) && !empty($validated['tenant_role_id'])) {
            $tr = \App\Models\TenantRole::find($validated['tenant_role_id']);
            if ($tr) {
                // Map the name loosely to legacy role for backward compatibility
                $n = strtolower($tr->name);
                if (str_contains($n, 'admin')) $validated['role'] = 'admin';
                elseif (str_contains($n, 'dpo')) $validated['role'] = 'dpo';
                elseif (str_contains($n, 'viewer')) $validated['role'] = 'viewer';
                else $validated['role'] = 'maker';
            }
        }

        $user = User::create($validated);

        $user->load(['organization', 'tenantRole']);

        return response()->json(['data' => $user, 'message' => 'User created'], 201);
    }

    /**
     * Show a single user.
     */
    public function show(Request $request, string $id)
    {
        $auth = $request->user();
        $user = User::with(['organization', 'tenantRole'])->findOrFail($id);

        // Scope check
        if ($auth->role !== 'superadmin' && $user->org_id !== $auth->org_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => $user]);
    }

    /**
     * Update user.
     */
    public function update(Request $request, string $id)
    {
        if ($denied = $this->checkPerm($request, 'write')) return $denied;
        $auth = $request->user();
        $user = User::findOrFail($id);

        // Scope check
        if ($auth->role !== 'superadmin' && $user->org_id !== $auth->org_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Only superadmin/admin can update other users
        if (!in_array($auth->role, ['superadmin', 'admin']) && $auth->id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', 'nullable'],
            'tenant_role_id' => 'sometimes|nullable|exists:tenant_roles,id',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];

        if ($auth->role === 'superadmin') {
            $rules['role'] = ['sometimes'];
            $rules['org_id'] = 'nullable|exists:organizations,id';
        }

        if ($request->filled('password')) {
            $rules['password'] = 'string|min:8';
        }

        $validated = $request->validate($rules);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        // Auto-create Organization if superadmin changes a role to 'admin' and no org_id is present
        $newRole = $validated['role'] ?? $user->role;
        $newOrgId = array_key_exists('org_id', $validated) ? $validated['org_id'] : $user->org_id;

        if ($auth->role === 'superadmin') {
            if ($newRole === 'admin' && empty($newOrgId)) {
                $orgName = ($validated['name'] ?? $user->name) . "'s Organization";
                $org = \App\Models\Organization::create([
                    'name' => $orgName,
                    'slug' => \Illuminate\Support\Str::slug($orgName . '-' . uniqid()),
                    'industry' => $request->input('org_industry', 'Other'),
                    'org_level' => $request->input('org_level', 'holding'),
                    'parent_id' => $request->input('parent_id') ?: null,
                ]);
                $validated['org_id'] = $org->id;
            } elseif ($newRole === 'superadmin') {
                $validated['org_id'] = null; // Ensure superadmins have no org_id
            }
        }

        if (isset($validated['tenant_role_id'])) {
            $tr = \App\Models\TenantRole::find($validated['tenant_role_id']);
            if ($tr && !isset($validated['role'])) {
                $n = strtolower($tr->name);
                if (str_contains($n, 'admin')) $validated['role'] = 'admin';
                elseif (str_contains($n, 'dpo')) $validated['role'] = 'dpo';
                elseif (str_contains($n, 'viewer')) $validated['role'] = 'viewer';
                else $validated['role'] = 'maker';
            }
        }

        $user->update($validated);

        $user->load(['organization', 'tenantRole']);

        return response()->json(['data' => $user, 'message' => 'User updated']);
    }

    /**
     * Soft delete user.
     */
    public function destroy(Request $request, string $id)
    {
        if ($denied = $this->checkPerm($request, 'write')) return $denied;
        $auth = $request->user();
        $user = User::findOrFail($id);

        if ($auth->role !== 'superadmin' && $user->org_id !== $auth->org_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($auth->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Can't delete yourself
        if ($auth->id === $user->id) {
            return response()->json(['message' => 'Cannot delete yourself'], 400);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted']);
    }

    /**
     * Restore soft-deleted user.
     */
    public function restore(Request $request, string $id)
    {
        if ($denied = $this->checkPerm($request, 'write')) return $denied;
        $auth = $request->user();
        $user = User::onlyTrashed()->findOrFail($id);

        if ($auth->role !== 'superadmin' && $user->org_id !== $auth->org_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($auth->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user->restore();
        $user->load(['organization', 'tenantRole']);

        return response()->json(['data' => $user, 'message' => 'User restored']);
    }
}
