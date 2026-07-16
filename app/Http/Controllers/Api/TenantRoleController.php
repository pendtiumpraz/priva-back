<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\MenuItem;
use App\Models\TenantMenuOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Services\MenuRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantRoleController extends Controller
{
    /**
     * Cek tenant punya license perpetual aktif (Enterprise On-Premise).
     * Custom role hanya tersedia untuk paket beli-putus.
     */
    private function ensurePerpetualLicense(Request $request): ?JsonResponse
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Tenant tidak punya organisasi.'], 403);
        }
        $license = License::where('org_id', $orgId)
            ->where('status', 'active')
            ->where('license_type', 'perpetual')
            ->first();
        if (! $license) {
            return response()->json(['message' => 'Fitur Role Management hanya tersedia untuk lisensi Enterprise On-Premise (Beli Putus).'], 403);
        }

        return null;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (in_array($user->role, ['root', 'superadmin'], true)) {
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
        if ($denial = $this->ensurePerpetualLicense($request)) {
            return $denial;
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        $role = TenantRole::create([
            'org_id' => $request->user()->org_id,
            'name' => $request->name,
            'description' => $request->description,
            'permissions' => $request->permissions ?? [],
            'is_system' => false,
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
        if ($denial = $this->ensurePerpetualLicense($request)) {
            return $denial;
        }

        $role = TenantRole::where('org_id', $request->user()->org_id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        // System (built-in) roles CAN be edited — permissions, description, and
        // name are all customizable per tenant. Only deletion stays blocked
        // (see destroy) so a tenant can't remove a role the platform relies on.
        $role->update($request->only('name', 'description', 'permissions'));

        // Jaga konsistensi dua arah dengan Menu Preferences: untuk menu yang
        // punya modul-permission, override visibilitas per-role diselaraskan
        // dengan permissions. Jadi memberi/mencabut akses di Role Settings
        // langsung tercermin di toggle Menu Preferences (dan sebaliknya).
        if ($request->has('permissions')) {
            $this->syncMenuOverridesToPermissions($role);
        }

        return response()->json(['data' => $role, 'message' => 'Role berhasil diupdate']);
    }

    /**
     * Selaraskan TenantMenuOverride sebuah role dengan permissions-nya, untuk
     * menu yang punya modul-permission. Role dengan wildcard '*' dilewati
     * (semua terlihat; tidak diekspansi). Mencegah kondisi "izin ada tapi menu
     * tersembunyi oleh override basi" saat Role Settings & Menu Preferences
     * diubah dari layar berbeda.
     */
    private function syncMenuOverridesToPermissions(TenantRole $role): void
    {
        $perms = is_array($role->permissions) ? $role->permissions : [];
        if (in_array('*', $perms, true)) {
            return;
        }

        foreach (MenuItem::get(['id', 'menu_key']) as $menu) {
            $module = MenuRegistryService::permissionModuleForMenuKey($menu->menu_key);
            if ($module === null) {
                continue;
            }
            $visible = MenuRegistryService::roleGrantsMenu($perms, $module);
            TenantMenuOverride::updateOrCreate(
                ['org_id' => $role->org_id, 'menu_id' => $menu->id, 'tenant_role_id' => $role->id],
                ['is_visible' => $visible, 'role' => null]
            );
        }
    }

    /**
     * Daftar module ID yang tenant punya akses — mirror MenuRegistryService::forUser
     * supaya RoleEditor di /settings konsisten dengan apa yang bener-bener visible
     * di sidebar.
     *
     * Module di-include kalau:
     *   - TIDAK revoked (record dengan is_entitled=false / valid_until expired), DAN
     *   - explicit grant (record is_entitled=true) — bypass license package gate, ATAU
     *   - default (no record) DAN tenant license package match menu.required_packages
     *     (kalau menu tidak set required_packages → default available)
     */
    public function entitledModules(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['data' => []]);
        }

        $allMenus = MenuItem::get(['id', 'menu_key', 'required_packages']);
        $entitlements = TenantModuleEntitlement::where('org_id', $orgId)->get();

        $granted = [];
        $revoked = [];
        foreach ($entitlements as $e) {
            if ($e->isActive()) {
                $granted[$e->menu_id] = true;
            } else {
                $revoked[$e->menu_id] = true;
            }
        }

        $packageType = License::where('org_id', $orgId)
            ->where('status', 'active')
            ->value('package_type');

        $entitled = [];
        foreach ($allMenus as $menu) {
            if (isset($revoked[$menu->id])) {
                continue;
            }

            $explicitGrant = isset($granted[$menu->id]);
            if (! $explicitGrant) {
                // Default path: license package gate
                $required = $menu->required_packages;
                if (is_array($required) && count($required) > 0) {
                    if (! $packageType || ! in_array($packageType, $required, true)) {
                        continue;
                    }
                }
            }

            $key = $menu->menu_key;
            $entitled[] = $key;
            // Normalize hyphen → underscore untuk match permission key
            // (e.g. menu_key=data-discovery, permission key=data_discovery)
            $underscored = str_replace('-', '_', $key);
            if ($underscored !== $key) {
                $entitled[] = $underscored;
            }
        }

        return response()->json([
            'data' => array_values(array_unique($entitled)),
            'org_id' => $orgId,
        ]);
    }

    public function destroy($id)
    {
        $role = TenantRole::where('org_id', auth()->user()->org_id)->findOrFail($id);

        if ($role->is_system) {
            return response()->json(['message' => 'Role bawaan tidak dapat dihapus.'], 400);
        }

        if ($role->users()->count() > 0) {
            return response()->json(['message' => 'Tidak dapat menghapus role karena masih digunakan oleh user.'], 400);
        }

        $role->delete();

        return response()->json(['message' => 'Role berhasil dihapus']);
    }
}
