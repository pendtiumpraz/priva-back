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
        if (!$user || in_array($user->role, ['root','superadmin'], true)) return null;

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

        if (in_array($auth->role, ['root','superadmin'], true)) {
            // Superadmin can filter by org_id optionally
            if ($request->filled('org_id')) {
                $query->where('org_id', $request->org_id);
            }
        } else {
            // Include their own org users
            // AND the admin users of their descendant organizations ONLY (not staff)
            if (in_array($auth->role, ['admin']) && $auth->org_id) {
                // Determine descendant org IDs via Organization model (assuming getDescendantIds exists)
                $descendantIds = $auth->organization ? $auth->organization->getDescendantIds() : [];
                
                if (!empty($descendantIds)) {
                    $query->where(function ($q) use ($auth, $descendantIds) {
                        $q->where('org_id', $auth->org_id) // My own org's users
                          ->orWhere(function ($subQ) use ($descendantIds) {
                              $subQ->whereIn('org_id', $descendantIds)->where('role', 'admin'); // Only admins of descendants
                          });
                    });
                } else {
                    $query->where('org_id', $auth->org_id);
                }
            } else {
                $query->where('org_id', $auth->org_id);
            }
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
        // Role exclude — accept comma-separated list, e.g. ?exclude_roles=admin,superadmin,root
        if ($request->filled('exclude_roles')) {
            $excluded = array_filter(array_map('trim', explode(',', $request->get('exclude_roles'))));
            if (!empty($excluded)) {
                $query->whereNotIn('role', $excluded);
            }
        }

        // Department filter — either a department UUID or a division *name*
        // (the latter for Step-0 pickers that only know the divisi label).
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('department_name')) {
            $query->whereHas('department', function ($q) use ($request) {
                $q->where('name', $request->department_name);
            });
        }

        // Also accept `q` as an alias for search (LazySearchSelect convention).
        if ($request->filled('q') && !$request->filled('search')) {
            $s = $request->q;
            $query->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%");
            });
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
            // Length & complexity di-validasi terpisah lewat PasswordPolicyService
            // di bawah supaya bisa ngasih multiple-message yang detail.
            'password' => 'required|string',
            'role' => ['sometimes', 'nullable'], // Keep as optional legacy fallback
            'tenant_role_id' => 'required|exists:tenant_roles,id',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'position_id' => 'nullable|uuid|exists:positions,id',
            'department_id' => 'nullable|uuid|exists:departments,id',
        ];

        // Role restriction modification
        $isCreatingNewOrg = $request->input('role') === 'admin' && empty($request->input('org_id'));
        
        if (in_array($auth->role, ['root','superadmin'], true) || ($auth->role === 'admin' && $isCreatingNewOrg)) {
            $rules['role'] = ['sometimes'];
            $rules['tenant_role_id'] = ['nullable', 'exists:tenant_roles,id'];
            
            if (in_array($auth->role, ['root','superadmin'], true)) {
                if (in_array($request->input('role'), ['dpo', 'maker', 'viewer'])) {
                    $rules['org_id'] = 'required|exists:organizations,id';
                } else {
                    $rules['org_id'] = 'nullable|exists:organizations,id';
                }
            } else {
                $rules['org_id'] = 'nullable|exists:organizations,id';
            }
        } else {
            // Admin users creating staff will automatically get the org_id from their parent
            if (! in_array($auth->role, ['root','superadmin'], true)) {
                $rules['org_id'] = 'nullable';
            }
        }

        $validated = $request->validate($rules);

        // Password policy — admin yang create user juga harus tunduk policy.
        $policy = app(\App\Services\PasswordPolicyService::class);
        $violations = $policy->validate($validated['password'], $validated['email']);
        if (! empty($violations)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password' => array_column($violations, 'message'),
            ]);
        }

        $validated['password'] = Hash::make($validated['password']);
        $validated['password_changed_at'] = now();

        // Auto-create Organization if admin creates an 'admin' with org creation data
        if ((in_array($auth->role, ['root','superadmin'], true) || $auth->role === 'admin') && $validated['role'] === 'admin' && empty($validated['org_id'])) {
            $orgName = $request->input('org_name', $validated['name'] . "'s Organization");
            $orgSlug = $request->input('org_slug', \Illuminate\Support\Str::slug($orgName . '-' . uniqid()));
            $orgIndustry = $request->input('org_industry', 'Other');
            $orgLevel = $request->input('org_level', 'holding');
            $parentId = $request->input('parent_id');
            
            // If the creator is an admin of a holding company, ensure parent_id is under their control
            if ($auth->role === 'admin') {
                if (!$parentId) $parentId = $auth->org_id; // Default to their org
            }
            
            $org = \App\Models\Organization::create([
                'name' => $orgName,
                'slug' => $orgSlug,
                'industry' => $orgIndustry,
                'org_level' => $orgLevel,
                'parent_id' => $parentId ?: null,
            ]);
            $validated['org_id'] = $org->id;

            // Create default system roles for the new org
            $allModules = ['dashboard', 'gap_assessment', 'ropa', 'dpia', 'lia', 'tia', 'maturity', 'data_discovery', 'contract_review', 'vendor_risk', 'cross_border', 'dsr', 'consent', 'cookie', 'breach', 'security', 'simulation', 'users', 'settings'];
            $allWrite = []; $allRead = [];
            foreach ($allModules as $mod) { $allWrite[] = "$mod:read"; $allWrite[] = "$mod:write"; $allRead[] = "$mod:read"; }

            $adminRole = \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'Admin', 'is_system' => true, 'description' => 'Administrator dengan full akses', 'permissions' => ['*']]);
            \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'DPO', 'is_system' => true, 'description' => 'Data Protection Officer', 'permissions' => $allWrite]);
            \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'Maker', 'is_system' => true, 'description' => 'User operasional', 'permissions' => array_filter($allWrite, fn($p) => !str_contains($p, 'users') && !str_contains($p, 'settings'))]);
            \App\Models\TenantRole::create(['org_id' => $org->id, 'name' => 'Viewer', 'is_system' => true, 'description' => 'Akses read-only', 'permissions' => $allRead]);
            
            $validated['tenant_role_id'] = $adminRole->id;
        } else if (! in_array($auth->role, ['root','superadmin'], true) && empty($validated['org_id'])) {
            $validated['org_id'] = $auth->org_id;
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

        // Seed default notification preferences based on role.
        try {
            \App\Services\NotificationPreferenceDefaults::seedForUser($user);
        } catch (\Exception $e) {
            \Log::warning('Failed to seed notification preferences: ' . $e->getMessage());
        }

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
        $descendantIds = ($auth->role === 'admin' && $auth->organization) ? $auth->organization->getDescendantIds() : [];
        if (! in_array($auth->role, ['root','superadmin'], true) && $user->org_id !== $auth->org_id) {
            if ($auth->role === 'admin' && in_array($user->org_id, $descendantIds) && $user->role === 'admin') {
                // Allowed
            } else {
                return response()->json(['message' => 'Forbidden'], 403);
            }
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
        $descendantIds = ($auth->role === 'admin' && $auth->organization) ? $auth->organization->getDescendantIds() : [];
        if (! in_array($auth->role, ['root','superadmin'], true) && $user->org_id !== $auth->org_id) {
            if ($auth->role === 'admin' && in_array($user->org_id, $descendantIds) && $user->role === 'admin') {
                // Allowed
            } else {
                return response()->json(['message' => 'Forbidden'], 403);
            }
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
            'position_id' => 'nullable|uuid|exists:positions,id',
            'department_id' => 'nullable|uuid|exists:departments,id',
            'is_active' => 'sometimes|boolean',
        ];

        if (in_array($auth->role, ['root','superadmin'], true)) {
            $rules['role'] = ['sometimes'];
            $rules['org_id'] = 'nullable|exists:organizations,id';
        }
        
        // Allowed org update fields for passing to organization model
        if ($request->has('org_name')) {
            $rules['org_name'] = 'sometimes|string|max:255';
        }

        if ($request->filled('password')) {
            $rules['password'] = 'string';
        }

        $validated = $request->validate($rules);

        if (isset($validated['password'])) {
            // Policy check sebelum hash. Pakai email baru kalau ada di payload,
            // fallback ke email user existing.
            $policy = app(\App\Services\PasswordPolicyService::class);
            $email = $validated['email'] ?? $user->email;
            $violations = $policy->validate($validated['password'], $email);
            if (! empty($violations)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'password' => array_column($violations, 'message'),
                ]);
            }
            $validated['password'] = Hash::make($validated['password']);
            $validated['password_changed_at'] = now();
        }

        // Auto-create Organization if superadmin changes a role to 'admin' and no org_id is present
        $newRole = $validated['role'] ?? $user->role;
        $newOrgId = array_key_exists('org_id', $validated) ? $validated['org_id'] : $user->org_id;

        if (in_array($auth->role, ['root','superadmin'], true)) {
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

        // Update User's Organization Name/Level/Parent
        if ((in_array($auth->role, ['root','superadmin'], true) || ($auth->role === 'admin' && in_array($user->org_id, $descendantIds))) && $user->org_id) {
            $orgUpdateData = [];
            
            if ($request->has('org_name')) {
                $orgUpdateData['name'] = $request->input('org_name');
            }

            if (in_array($auth->role, ['root','superadmin'], true) && ($request->has('org_level') || $request->has('parent_id'))) {
                $orgLevelUpdate = $request->input('org_level');
                $parentIdUpdate = $request->input('parent_id');
                
                $orgUpdateData['org_level'] = $orgLevelUpdate ?: $user->organization->org_level;
                $orgUpdateData['parent_id'] = $parentIdUpdate === 'null' ? null : ($parentIdUpdate ?: $user->organization->parent_id);
            }

            if (!empty($orgUpdateData)) {
                $user->organization->update($orgUpdateData);
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

        if (! in_array($auth->role, ['root','superadmin'], true) && $user->org_id !== $auth->org_id) {
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

        if (! in_array($auth->role, ['root','superadmin'], true) && $user->org_id !== $auth->org_id) {
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
