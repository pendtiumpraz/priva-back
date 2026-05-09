<?php

namespace App\Services;

use App\Models\License;
use App\Models\MenuItem;
use App\Models\RoleMenuWhitelist;
use App\Models\TenantMenuOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\User;

/**
 * 3-layer menu visibility resolver:
 *   Layer 0a (Entitlement REVOKED): root explicit lock — selalu hidden
 *   Layer 0b (Entitlement GRANTED): tenant beli add-on → bypass whitelist + license gate
 *   Layer 1  (Role whitelist):       global default per-role (kalau gak ada entitlement explicit)
 *   Layer 2  (Tenant override):      admin tenant hide per-role (selalu di-honor)
 *
 * Resolusi (untuk non-root, non-superadmin):
 *   - revoked → hidden
 *   - entitled (explicit grant) → visible (bypass Layer 1 & license gate, tetap honor Layer 2)
 *   - default (no entitlement record) → visible kalau whitelist + license gate match (& Layer 2 ok)
 *
 * Rasional SaaS: tenant yg sudah bayar add-on tidak boleh kehilangan akses karena
 * root mengubah whitelist global. Whitelist = default platform; entitlement = pembelian.
 */
class MenuRegistryService
{
    /**
     * Effective menu list for a user. Root sees everything (bypasses entitlement).
     * Returns array of menu rows (with label/href/icon/section/sort_order).
     */
    public static function forUser(User $user): array
    {
        $role = $user->role ?? 'viewer';
        $orgId = $user->org_id;

        // Load ALL menu_items once — cacheable per role.
        $all = MenuItem::orderBy('sort_order')->get();

        // Layer 1: role whitelist — which menus is this role allowed to ever see?
        // Root MUST respect their own whitelist so /menu-preferences toggles work;
        // the bypass here only skips tenant-scoped layers (entitlement + override)
        // because root has no org.
        $whitelistedMenuIds = RoleMenuWhitelist::where('role', $role)
            ->where('is_allowed', true)
            ->pluck('menu_id')
            ->toArray();

        if ($role === 'root') {
            $visible = [];
            $visibleIds = [];
            foreach ($all as $menu) {
                if (! in_array($menu->id, $whitelistedMenuIds, true)) {
                    continue;
                }
                if ($menu->parent_menu_id && ! isset($visibleIds[$menu->parent_menu_id])) {
                    continue;
                }
                $visible[] = self::toArray($menu);
                $visibleIds[$menu->id] = true;
            }

            return $visible;
        }

        // Layer 0: entitlement records.
        //   isActive()=true   → explicit grant (bypass whitelist + license gate)
        //   isActive()=false  → revoked (always hidden, ignore whitelist)
        //   no record         → default path (whitelist + license gate menentukan)
        $entitlements = [];
        $revoked = [];
        if ($orgId) {
            $rows = TenantModuleEntitlement::where('org_id', $orgId)->get();
            foreach ($rows as $e) {
                if ($e->isActive()) {
                    $entitlements[$e->menu_id] = true;
                } else {
                    $revoked[$e->menu_id] = true;
                }
            }
        }

        // Layer 2: tenant override — admin-hidden per role.
        // Prioritas resolusi:
        //   1. Override per tenant_role_id (kalau user punya custom role)
        //   2. Fallback override per legacy role string
        // Custom role yang gak match keyword tetap punya legacy role mapping
        // di User.role, jadi legacy override masih efektif sebagai default.
        $hidden = [];
        if ($orgId) {
            $tenantRoleId = $user->tenant_role_id ?? null;

            $q = TenantMenuOverride::where('org_id', $orgId)->where('is_visible', false);
            if ($tenantRoleId) {
                $q->where(function ($w) use ($tenantRoleId, $role) {
                    $w->where('tenant_role_id', $tenantRoleId)
                        ->orWhere(function ($leg) use ($role) {
                            $leg->whereNull('tenant_role_id')->where('role', $role);
                        });
                });
            } else {
                $q->whereNull('tenant_role_id')->where('role', $role);
            }

            $hidden = array_flip($q->pluck('menu_id')->toArray());
        }

        // Layer 0.5: license package gate. Tenant's active license package_type
        // must match menu_item.required_packages (if set). Null → available to all.
        // Superadmin bypass package gate — mereka platform-level admin, boleh
        // akses semua feature regardless of license. (Root sudah bypass earlier.)
        $packageType = null;
        $bypassPackageGate = ($role === 'superadmin');
        if ($orgId && ! $bypassPackageGate) {
            $packageType = License::where('org_id', $orgId)
                ->where('status', 'active')
                ->value('package_type');
        }

        $whitelistedSet = array_flip($whitelistedMenuIds);

        $visible = [];
        $visibleIds = [];
        foreach ($all as $menu) {
            // Layer 0a — root explicit revoke selalu menutup akses.
            if (isset($revoked[$menu->id])) {
                continue;
            }

            $explicitlyEntitled = isset($entitlements[$menu->id]);

            // Kalau tenant TIDAK punya entitlement explicit, jalankan default
            // gating: whitelist + license package. Kalau punya entitlement
            // explicit (mis. beli add-on), bypass dua layer ini — tenant
            // sudah bayar dan harus tetap dapat akses walau whitelist global
            // berubah atau paket license-nya tidak match.
            if (! $explicitlyEntitled) {
                if (! isset($whitelistedSet[$menu->id])) {
                    continue;
                }
                if (! $bypassPackageGate) {
                    $required = $menu->required_packages;
                    if (is_array($required) && count($required) > 0) {
                        if (! $packageType || ! in_array($packageType, $required, true)) {
                            continue;
                        }
                    }
                }
            }

            // Layer 2 — admin tenant tetap punya hak hide menu untuk role
            // tertentu di org-nya, bahkan kalau ada entitlement.
            if (isset($hidden[$menu->id])) {
                continue;
            }

            // Sub-item: parent harus juga visible.
            if ($menu->parent_menu_id && ! isset($visibleIds[$menu->parent_menu_id])) {
                continue;
            }
            $visible[] = self::toArray($menu);
            $visibleIds[$menu->id] = true;
        }

        return $visible;
    }

    private static function toArray(MenuItem $m): array
    {
        return [
            'id' => $m->id,
            'parent_menu_id' => $m->parent_menu_id,
            'menu_key' => $m->menu_key,
            'label' => $m->label,
            'href' => $m->href,
            'icon' => $m->icon,
            'section' => $m->section,
            'sort_order' => $m->sort_order,
            'hideable' => $m->hideable,
        ];
    }
}
