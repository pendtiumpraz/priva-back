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
     * List users.
     * - superadmin: sees ALL users across ALL tenants (+ optional org_id filter)
     * - admin: sees only users within their own org
     */
    public function index(Request $request)
    {
        $auth = $request->user();
        $query = User::with('organization');

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
        $auth = $request->user();

        // Only superadmin and admin can create users
        if (!in_array($auth->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['admin', 'dpo', 'maker', 'viewer'])],
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
        ];

        // Role restriction modification
        if ($auth->role === 'superadmin') {
            $rules['role'] = ['required', Rule::in(['superadmin', 'admin', 'dpo', 'maker', 'viewer'])];
            
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

        // Auto-create Organization if superadmin creates an 'admin' and no org_id is passed
        if ($auth->role === 'superadmin' && $validated['role'] === 'admin' && empty($validated['org_id'])) {
            $orgName = $validated['name'] . "'s Organization";
            $org = \App\Models\Organization::create([
                'name' => $orgName,
                'slug' => \Illuminate\Support\Str::slug($orgName . '-' . uniqid()),
                'industry' => 'Other',
            ]);
            $validated['org_id'] = $org->id;
        }

        $validated['is_active'] = true;

        $user = User::create($validated);

        if ($user->role !== 'superadmin' && $user->org_id) {
            $tenantRole = \App\Models\TenantRole::where('org_id', $user->org_id)
                ->where('name', ucfirst($user->role))
                ->first();
            if ($tenantRole) {
                $user->tenant_role_id = $tenantRole->id;
                $user->save();
            }
        }

        $user->load('organization');

        return response()->json(['data' => $user, 'message' => 'User created'], 201);
    }

    /**
     * Show a single user.
     */
    public function show(Request $request, string $id)
    {
        $auth = $request->user();
        $user = User::with('organization')->findOrFail($id);

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
            'role' => ['sometimes', Rule::in(['admin', 'dpo', 'maker', 'viewer'])],
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];

        if ($auth->role === 'superadmin') {
            $rules['role'] = ['sometimes', Rule::in(['superadmin', 'admin', 'dpo', 'maker', 'viewer'])];
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
                    'industry' => 'Other',
                ]);
                $validated['org_id'] = $org->id;
            } elseif ($newRole === 'superadmin') {
                $validated['org_id'] = null; // Ensure superadmins have no org_id
            }
        }

        $user->update($validated);

        if ($user->role !== 'superadmin' && $user->org_id) {
            $tenantRole = \App\Models\TenantRole::where('org_id', $user->org_id)
                ->where('name', ucfirst($user->role))
                ->first();
            if ($tenantRole) {
                $user->tenant_role_id = $tenantRole->id;
                $user->save();
            }
        }

        $user->load('organization');

        return response()->json(['data' => $user, 'message' => 'User updated']);
    }

    /**
     * Soft delete user.
     */
    public function destroy(Request $request, string $id)
    {
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
        $auth = $request->user();
        $user = User::onlyTrashed()->findOrFail($id);

        if ($auth->role !== 'superadmin' && $user->org_id !== $auth->org_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($auth->role, ['superadmin', 'admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user->restore();
        $user->load('organization');

        return response()->json(['data' => $user, 'message' => 'User restored']);
    }
}
