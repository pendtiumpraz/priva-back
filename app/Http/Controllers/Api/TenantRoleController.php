<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TenantRole;

class TenantRoleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (in_array($user->role, ['root','superadmin'], true)) {
            $query = TenantRole::with('organization:id,name');
            if ($request->filled('org_id')) {
                $query->where('org_id', $request->org_id);
            }
            $roles = $query->withCount('users')->orderBy('org_id')->orderBy('name')->get();
            return response()->json(['data' => $roles]);
        }

        $roles = TenantRole::where('org_id', $user->org_id)->withCount('users')->orderBy('name')->get();
        return response()->json(['data' => $roles]);
    }

    public function store(Request $request)
    {
        // Enforce license check: "beli putus" / perpetual
        $license = $request->user()->organization->activeLicense();
        if (!$license || $license->license_type !== 'perpetual') {
            return response()->json(['message' => 'Fitur Role Management hanya tersedia untuk lisensi Enterprise On-Premise (Beli Putus).'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'nullable|array',
        ]);

        $role = TenantRole::create([
            'org_id' => $request->user()->org_id,
            'name' => $request->name,
            'description' => $request->description,
            'permissions' => $request->permissions ?? [],
        ]);

        return response()->json(['data' => $role, 'message' => 'Role berhasil dibuat'], 201);
    }

    public function show($id)
    {
        $role = TenantRole::where('org_id', auth()->user()->org_id)->findOrFail($id);
        return response()->json(['data' => $role]);
    }

    public function update(Request $request, $id)
    {
        // Enforce license check again
        $license = $request->user()->organization->activeLicense();
        if (!$license || $license->license_type !== 'perpetual') {
            return response()->json(['message' => 'Fitur Role Management hanya tersedia untuk lisensi Enterprise On-Premise (Beli Putus).'], 403);
        }

        $role = TenantRole::where('org_id', $request->user()->org_id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'permissions' => 'nullable|array',
        ]);

        $role->update($request->only('name', 'description', 'permissions'));
        return response()->json(['data' => $role, 'message' => 'Role berhasil diupdate']);
    }

    public function destroy($id)
    {
        $role = TenantRole::where('org_id', auth()->user()->org_id)->findOrFail($id);
        
        if ($role->is_system) {
            return response()->json(['message' => 'Tidak dapat menghapus System Role bawan.'], 400);
        }

        if ($role->users()->count() > 0) {
            return response()->json(['message' => 'Tidak dapat menghapus role karena masih digunakan oleh user.'], 400);
        }
        
        $role->delete();
        return response()->json(['message' => 'Role berhasil dihapus']);
    }
}
