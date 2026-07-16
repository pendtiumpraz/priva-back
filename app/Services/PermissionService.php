<?php

namespace App\Services;

use App\Models\User;

/**
 * Single source of truth for tenant permission decisions.
 *
 * Previously this exact logic was duplicated in two places that CLAUDE.md
 * explicitly said to keep in sync:
 *   - App\Http\Middleware\CheckPermission (canonical version)
 *   - App\Http\Controllers\Api\ModuleCrudController::checkPermission()
 *
 * Both now delegate here so the rules can never drift apart. The middleware's
 * behavior is treated as canonical; see allows() for the one reconciled
 * difference (granular-action legacy fallback), which is a no-op in practice
 * because ModuleCrudController only ever asks for `read`/`write`.
 *
 * Decision rules (unchanged from the middleware):
 *   1. root / superadmin bypass everything.
 *   2. tenantRole->permissions is a JSON array of module ids:
 *        - '*'                 → full access
 *        - 'module'            → read
 *        - 'module:read'       → read
 *        - 'module:write'      → read + write
 *        - 'module:<action>'   → that granular action
 *      For read: module | module:read | module:write all grant.
 *      For write: only module:write.
 *      For a granular action: module:<action> | module:write.
 *   3. Legacy fallback (permissions is NOT an array): read is open to
 *      everyone; write/granular are open to admin/dpo/maker only.
 */
class PermissionService
{
    /**
     * Does $user have $ability on $moduleId?
     *
     * $moduleId must already be the permission module id (e.g. `data_discovery`,
     * not the `data-discovery` URL slug). Callers that route slugs are
     * responsible for mapping first.
     *
     * $ability is `read`, `write`, or a granular action name (e.g. `reveal`).
     */
    public function allows(User $user, string $moduleId, string $ability = 'read'): bool
    {
        // 1. Platform roles bypass all tenant permission checks.
        if (in_array($user->role, ['root', 'superadmin'], true)) {
            return true;
        }

        if (! $user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }

        $permissions = $user->tenantRole?->permissions ?? null;

        // 3. Legacy fallback — no structured permissions array.
        if (! is_array($permissions)) {
            if ($ability === 'read') {
                return true;
            }

            // write + any granular action require a privileged legacy role.
            return in_array($user->role, ['admin', 'dpo', 'maker'], true);
        }

        // 2. Structured permissions array. Normalize hyphen/underscore on the
        // module id AND the module part of each stored grant, so the form
        // written by the role editor (e.g. 'data-discovery:read') matches the
        // permission module id the route asks for (e.g. 'data_discovery') and
        // vice versa. Without this, the two separators never compare equal and
        // a role that visibly has "Data Discovery: read" is still denied.
        $normalize = static fn (string $s): string => str_replace('-', '_', $s);
        $moduleId = $normalize($moduleId);
        $permissions = array_map(static function ($p) use ($normalize) {
            $p = (string) $p;
            if ($p === '*') {
                return '*';
            }
            $parts = explode(':', $p, 2);
            $parts[0] = $normalize($parts[0]);

            return implode(':', $parts);
        }, $permissions);

        if (in_array('*', $permissions, true)) {
            return true;
        }

        if ($ability === 'write') {
            return in_array("{$moduleId}:write", $permissions, true);
        }

        if ($ability === 'read') {
            return in_array($moduleId, $permissions, true)
                || in_array("{$moduleId}:read", $permissions, true)
                || in_array("{$moduleId}:write", $permissions, true);
        }

        // Granular action (e.g. reveal): explicit grant, with module:write as
        // the implicit fallback so users who already have write don't lose the
        // action before roles are re-seeded.
        return in_array("{$moduleId}:{$ability}", $permissions, true)
            || in_array("{$moduleId}:write", $permissions, true);
    }
}
