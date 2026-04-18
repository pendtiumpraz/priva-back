<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\RoleMenuWhitelist;
use App\Models\TenantMenuOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 3-layer menu visibility resolver:
 *   Layer 0 (Entitlement): root-controlled per-tenant licensing
 *   Layer 1 (Role whitelist): root-controlled globally per role
 *   Layer 2 (Tenant override): admin-controlled, hide within allowed set
 *
 * final_visible = entitled ∧ role_whitelisted ∧ not_tenant_hidden
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

        // Root bypass: sees every menu regardless of whitelist/entitlement/override.
        if ($role === 'root') {
            return $all->map(fn($m) => self::toArray($m))->values()->toArray();
        }

        // Layer 1: role whitelist — which menus is this role allowed to ever see?
        $whitelistedMenuIds = RoleMenuWhitelist::where('role', $role)
            ->where('is_allowed', true)
            ->pluck('menu_id')
            ->toArray();

        // Layer 0: entitlement — which menus is THIS tenant licensed for?
        // If no row exists for (org_id, menu_id): default entitled=true.
        $entitlements = [];
        $revoked = [];
        if ($orgId) {
            $rows = TenantModuleEntitlement::where('org_id', $orgId)->get();
            foreach ($rows as $e) {
                if ($e->isActive()) $entitlements[$e->menu_id] = true;
                else $revoked[$e->menu_id] = true;
            }
        }

        // Layer 2: tenant override — admin-hidden per role
        $hidden = [];
        if ($orgId) {
            $rows = TenantMenuOverride::where('org_id', $orgId)
                ->where('role', $role)
                ->where('is_visible', false)
                ->pluck('menu_id')
                ->toArray();
            $hidden = array_flip($rows);
        }

        $visible = [];
        foreach ($all as $menu) {
            // Layer 1 — must be whitelisted for role
            if (!in_array($menu->id, $whitelistedMenuIds, true)) continue;

            // Layer 0 — if explicitly revoked, skip; otherwise default entitled
            if (isset($revoked[$menu->id])) continue;

            // Layer 2 — admin has hidden it
            if (isset($hidden[$menu->id])) continue;

            $visible[] = self::toArray($menu);
        }

        return $visible;
    }

    private static function toArray(MenuItem $m): array
    {
        return [
            'id' => $m->id,
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
