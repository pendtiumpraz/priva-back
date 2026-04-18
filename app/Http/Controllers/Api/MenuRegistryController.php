<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\RoleMenuWhitelist;
use App\Models\TenantMenuOverride;
use App\Models\TenantModuleEntitlement;
use App\Services\MenuRegistryService;
use Illuminate\Http\Request;

/**
 * Menu Registry API:
 *  - GET /menu-registry                   → effective menu for current user (3-layer resolved)
 *  - GET /menu-registry/all               → all menu_items (root only)
 *  - GET /menu-registry/whitelist         → (menu × role) matrix (root only)
 *  - PUT /menu-registry/whitelist         → toggle whitelist (root only)
 *  - GET /menu-registry/entitlements      → per-tenant entitlements (root only)
 *  - PUT /menu-registry/entitlements      → set/revoke entitlement (root only)
 *  - GET /menu-registry/tenant-overrides  → my tenant's overrides (admin+)
 *  - PUT /menu-registry/tenant-overrides  → toggle override (admin+, only within allowed set)
 */
class MenuRegistryController extends Controller
{
    // ──────────────────────────────────────────────
    // All users
    // ──────────────────────────────────────────────
    public function me(Request $request)
    {
        $menus = MenuRegistryService::forUser($request->user());
        return response()->json(['data' => $menus]);
    }

    // ──────────────────────────────────────────────
    // Root-only: manage Layer 1 (role whitelist)
    // ──────────────────────────────────────────────
    public function allMenus(Request $request)
    {
        $this->requireRoot($request);
        $items = MenuItem::orderBy('sort_order')->get();
        return response()->json(['data' => $items]);
    }

    public function whitelist(Request $request)
    {
        $this->requireRoot($request);
        $rows = RoleMenuWhitelist::with('menu')->get();
        return response()->json(['data' => $rows]);
    }

    public function updateWhitelist(Request $request)
    {
        $this->requireRoot($request);
        $data = $request->validate([
            'menu_id' => 'required|uuid|exists:menu_items,id',
            'role' => 'required|string|in:root,superadmin,admin,dpo,maker,viewer',
            'is_allowed' => 'required|boolean',
        ]);

        if ($data['role'] === 'root') {
            // Root always allowed for everything; do not persist a "disallow" for root.
            return response()->json(['message' => 'Root role cannot be restricted'], 422);
        }

        $before = RoleMenuWhitelist::where('menu_id', $data['menu_id'])->where('role', $data['role'])->value('is_allowed');
        $row = RoleMenuWhitelist::updateOrCreate(
            ['menu_id' => $data['menu_id'], 'role' => $data['role']],
            ['is_allowed' => $data['is_allowed']]
        );

        try {
            AuditLog::log('menu_registry', $row->id, 'whitelist_updated', [
                'menu_id' => $data['menu_id'], 'role' => $data['role'],
                'before' => $before, 'after' => $data['is_allowed'],
            ], 'role_whitelist');
        } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

        return response()->json(['message' => 'Whitelist diperbarui', 'data' => $row]);
    }

    // ──────────────────────────────────────────────
    // Root-only: manage Layer 0 (tenant entitlements)
    // ──────────────────────────────────────────────
    public function entitlements(Request $request)
    {
        $this->requireRoot($request);

        if ($request->filled('org_id')) {
            $rows = TenantModuleEntitlement::where('org_id', $request->org_id)->get();
            return response()->json(['data' => $rows]);
        }

        // Return entitlement matrix per-org summary
        $orgs = Organization::select('id', 'name', 'slug')->orderBy('name')->get();
        $menus = MenuItem::orderBy('sort_order')->get();
        $entitlements = TenantModuleEntitlement::all()->groupBy('org_id');

        return response()->json([
            'orgs' => $orgs,
            'menus' => $menus,
            'entitlements' => $entitlements,
        ]);
    }

    public function updateEntitlement(Request $request)
    {
        $this->requireRoot($request);
        $data = $request->validate([
            'org_id' => 'required|uuid|exists:organizations,id',
            'menu_id' => 'required|uuid|exists:menu_items,id',
            'is_entitled' => 'required|boolean',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $before = TenantModuleEntitlement::where('org_id', $data['org_id'])->where('menu_id', $data['menu_id'])->first();
        $row = TenantModuleEntitlement::updateOrCreate(
            ['org_id' => $data['org_id'], 'menu_id' => $data['menu_id']],
            [
                'is_entitled' => $data['is_entitled'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );

        try {
            AuditLog::log('menu_registry', $row->id, 'entitlement_updated', [
                'org_id' => $data['org_id'], 'menu_id' => $data['menu_id'],
                'before' => $before?->is_entitled, 'after' => $data['is_entitled'],
                'valid_until' => $data['valid_until'] ?? null, 'notes' => $data['notes'] ?? null,
            ], 'tenant_entitlement');
        } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

        return response()->json(['message' => 'Entitlement diperbarui', 'data' => $row]);
    }

    // ──────────────────────────────────────────────
    // Tenant admin (+ root): Layer 2 override
    // Root (no org_id) → edits apply globally to every tenant.
    // Superadmin is NOT allowed here — manage their scope via /menu-control.
    // ──────────────────────────────────────────────
    public function tenantOverrides(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['root', 'admin'], true)) {
            return response()->json(['message' => 'Hanya root atau admin tenant yang bisa akses'], 403);
        }

        // Root without org_id → aggregate "consensus" view across all tenants:
        // return one row per (menu,role) only when every tenant agrees on the value.
        if ($user->role === 'root' && !$user->org_id && !$request->filled('org_id')) {
            $orgCount = \App\Models\Organization::count();
            $grouped = TenantMenuOverride::select('menu_id', 'role', 'is_visible')
                ->selectRaw('COUNT(*) as c')
                ->groupBy('menu_id', 'role', 'is_visible')
                ->get();

            $agg = [];
            foreach ($grouped as $g) {
                $k = $g->menu_id . ':' . $g->role;
                if (!isset($agg[$k])) $agg[$k] = ['menu_id' => $g->menu_id, 'role' => $g->role, 'votes' => []];
                $agg[$k]['votes'][$g->is_visible ? '1' : '0'] = (int) $g->c;
            }
            $rows = [];
            foreach ($agg as $a) {
                $total = array_sum($a['votes']);
                if ($total !== $orgCount || count($a['votes']) > 1) continue; // consensus requires unanimous
                $rows[] = [
                    'id' => '_global_' . $a['menu_id'] . '_' . $a['role'],
                    'org_id' => null,
                    'menu_id' => $a['menu_id'],
                    'role' => $a['role'],
                    'is_visible' => (bool) array_key_first($a['votes']),
                ];
            }
            return response()->json(['data' => $rows, 'scope' => 'global']);
        }

        $orgId = $user->role === 'root' && $request->filled('org_id')
            ? $request->org_id
            : $user->org_id;

        if (!$orgId) return response()->json(['data' => []]);

        $rows = TenantMenuOverride::where('org_id', $orgId)->get();
        return response()->json(['data' => $rows, 'scope' => 'tenant']);
    }

    public function updateTenantOverride(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['root', 'admin'], true)) {
            return response()->json(['message' => 'Hanya root atau admin tenant yang bisa akses'], 403);
        }

        // Root may also toggle platform roles (root, superadmin).
        // Tenant admins are constrained to the 4 tenant roles.
        $allowedRoles = $user->role === 'root'
            ? 'root,superadmin,admin,dpo,maker,viewer'
            : 'admin,dpo,maker,viewer';

        $data = $request->validate([
            'menu_id' => 'required|uuid|exists:menu_items,id',
            'role' => "required|string|in:{$allowedRoles}",
            'is_visible' => 'required|boolean',
        ]);

        $menu = MenuItem::findOrFail($data['menu_id']);
        if (!$menu->hideable) {
            return response()->json(['message' => 'Menu ini tidak bisa di-hide'], 422);
        }

        // Platform roles (root/superadmin) aren't scoped to any org — they
        // live in role_menu_whitelist, not tenant_menu_override. So when root
        // toggles visibility for those roles, upsert the whitelist row
        // directly (is_allowed = is_visible) and return.
        if (in_array($data['role'], ['root', 'superadmin'], true)) {
            if ($user->role !== 'root') {
                return response()->json(['message' => 'Hanya root yang boleh mengatur role platform'], 403);
            }
            $before = RoleMenuWhitelist::where('menu_id', $data['menu_id'])
                ->where('role', $data['role'])->value('is_allowed');
            $wl = RoleMenuWhitelist::updateOrCreate(
                ['menu_id' => $data['menu_id'], 'role' => $data['role']],
                ['is_allowed' => $data['is_visible']]
            );

            try {
                AuditLog::log('menu_registry', $wl->id, 'whitelist_toggled_via_preferences', [
                    'menu_id' => $data['menu_id'], 'role' => $data['role'],
                    'before' => $before, 'after' => $data['is_visible'],
                ], 'whitelist');
            } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

            return response()->json([
                'message' => 'Whitelist platform diperbarui',
                'data' => $wl,
                'scope' => 'whitelist',
            ]);
        }

        // Non-root must respect whitelist
        if ($user->role !== 'root') {
            $whitelisted = RoleMenuWhitelist::where('menu_id', $menu->id)
                ->where('role', $data['role'])
                ->where('is_allowed', true)
                ->exists();
            if (!$whitelisted) {
                return response()->json(['message' => 'Menu ini tidak di-whitelist oleh root untuk role tsb — tidak ada yg bisa di-toggle'], 422);
            }
        }

        // Root without org_id → bulk-apply to every organization.
        if ($user->role === 'root' && !$user->org_id && !$request->filled('org_id')) {
            $orgIds = \App\Models\Organization::pluck('id');
            foreach ($orgIds as $orgId) {
                TenantMenuOverride::updateOrCreate(
                    ['org_id' => $orgId, 'menu_id' => $data['menu_id'], 'role' => $data['role']],
                    ['is_visible' => $data['is_visible']]
                );
            }

            try {
                AuditLog::log('menu_registry', null, 'tenant_override_global', [
                    'menu_id' => $data['menu_id'], 'role' => $data['role'],
                    'is_visible' => $data['is_visible'], 'affected_tenants' => $orgIds->count(),
                ], 'tenant_override');
            } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

            return response()->json([
                'message' => "Override diterapkan ke {$orgIds->count()} tenant",
                'scope' => 'global',
                'affected' => $orgIds->count(),
            ]);
        }

        $orgId = $user->role === 'root' && $request->filled('org_id')
            ? $request->org_id
            : $user->org_id;
        if (!$orgId) return response()->json(['message' => 'org_id tidak ditemukan'], 422);

        $before = TenantMenuOverride::where('org_id', $orgId)
            ->where('menu_id', $data['menu_id'])->where('role', $data['role'])->value('is_visible');
        $row = TenantMenuOverride::updateOrCreate(
            ['org_id' => $orgId, 'menu_id' => $data['menu_id'], 'role' => $data['role']],
            ['is_visible' => $data['is_visible']]
        );

        try {
            AuditLog::log('menu_registry', $row->id, 'tenant_override_updated', [
                'org_id' => $orgId, 'menu_id' => $data['menu_id'], 'role' => $data['role'],
                'before' => $before, 'after' => $data['is_visible'],
            ], 'tenant_override');
        } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

        return response()->json(['message' => 'Override diperbarui', 'data' => $row, 'scope' => 'tenant']);
    }

    // ──────────────────────────────────────────────
    // Root-only: bulk entitlement (apply same config to many tenants)
    // ──────────────────────────────────────────────
    public function bulkEntitlement(Request $request)
    {
        $this->requireRoot($request);
        $data = $request->validate([
            'org_ids' => 'required|array|min:1|max:100',
            'org_ids.*' => 'uuid|exists:organizations,id',
            'menu_ids' => 'required|array|min:1',
            'menu_ids.*' => 'uuid|exists:menu_items,id',
            'is_entitled' => 'required|boolean',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $count = 0;
        foreach ($data['org_ids'] as $orgId) {
            foreach ($data['menu_ids'] as $menuId) {
                TenantModuleEntitlement::updateOrCreate(
                    ['org_id' => $orgId, 'menu_id' => $menuId],
                    [
                        'is_entitled' => $data['is_entitled'],
                        'valid_until' => $data['valid_until'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]
                );
                $count++;
            }
        }

        try {
            AuditLog::log('menu_registry', (string) \Illuminate\Support\Str::uuid(), 'bulk_entitlement_applied', [
                'org_count' => count($data['org_ids']), 'menu_count' => count($data['menu_ids']),
                'total_rows' => $count, 'is_entitled' => $data['is_entitled'],
            ], 'bulk_entitlement');
        } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

        return response()->json(['message' => "{$count} entitlement row diperbarui", 'count' => $count]);
    }

    public function copyEntitlement(Request $request)
    {
        $this->requireRoot($request);
        $data = $request->validate([
            'source_org_id' => 'required|uuid|exists:organizations,id',
            'target_org_ids' => 'required|array|min:1|max:100',
            'target_org_ids.*' => 'uuid|exists:organizations,id',
        ]);

        $source = TenantModuleEntitlement::where('org_id', $data['source_org_id'])->get();
        if ($source->isEmpty()) {
            return response()->json(['message' => 'Source tenant tidak memiliki entitlement config'], 422);
        }

        $count = 0;
        foreach ($data['target_org_ids'] as $targetId) {
            if ($targetId === $data['source_org_id']) continue;
            foreach ($source as $e) {
                TenantModuleEntitlement::updateOrCreate(
                    ['org_id' => $targetId, 'menu_id' => $e->menu_id],
                    [
                        'is_entitled' => $e->is_entitled,
                        'valid_until' => $e->valid_until,
                        'notes' => "Copied from tenant {$data['source_org_id']}",
                    ]
                );
                $count++;
            }
        }

        try {
            AuditLog::log('menu_registry', (string) \Illuminate\Support\Str::uuid(), 'entitlement_copied', [
                'source_org_id' => $data['source_org_id'],
                'target_count' => count($data['target_org_ids']),
                'total_rows' => $count,
            ], 'entitlement_copy');
        } catch (\Throwable $e) { \Log::warning('Audit log failed: ' . $e->getMessage()); }

        return response()->json(['message' => "Copy berhasil ke {$count} entry", 'count' => $count]);
    }

    public function auditLog(Request $request)
    {
        $this->requireRoot($request);
        $rows = AuditLog::where('module', 'menu_registry')
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->limit($request->get('limit', 100))
            ->get();
        return response()->json(['data' => $rows]);
    }

    // ──────────────────────────────────────────────
    private function requireRoot(Request $request): void
    {
        if (($request->user()->role ?? null) !== 'root') {
            abort(403, 'Hanya role root yang dapat mengakses endpoint ini.');
        }
    }
}
