<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPermission Middleware
 *
 * Validates that the authenticated user's tenant_role has the required permission
 * for the given module + action.
 *
 * Standard actions:
 *   - read   → requires `module`, `module:read`, or `module:write`, or `*`
 *   - write  → requires `module:write` or `*`
 *
 * Granular custom actions (e.g. data_discovery,reveal):
 *   - any action other than `read`/`write` is treated as a granular permission
 *     that must be explicitly granted via `module:action` or `*`. Falls back to
 *     `module:write` (the highest "standard" level) so DPOs that already have
 *     write don't lose access until the role config is re-seeded.
 *
 * Usage in routes:
 *   ->middleware('permission:ropa,read')
 *   ->middleware('permission:ropa,write')
 *   ->middleware('permission:data_discovery,reveal')
 *   ->middleware('permission:ropa')        // alias for permission:ropa,read
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'read'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Superadmin bypasses all permission checks
        if (in_array($user->role, ['root', 'superadmin'], true)) {
            return $next($request);
        }

        // Load tenant role if not loaded
        if (! $user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }

        $permissions = $user->tenantRole?->permissions ?? null;

        // If no tenant_role or no permissions array, fall back to legacy role-based check
        if (! is_array($permissions)) {
            // Legacy: admin, dpo, maker can read+write+granular; viewer can only read
            if ($action === 'read') {
                return $next($request);
            }
            if (in_array($user->role, ['admin', 'dpo', 'maker'])) {
                return $next($request);
            }

            return response()->json([
                'message' => "Akses ditolak — role Anda tidak memiliki izin {$action} untuk modul ini.",
            ], 403);
        }

        // Wildcard: full access
        if (in_array('*', $permissions)) {
            return $next($request);
        }

        if ($action === 'write') {
            // Write requires explicit module:write permission
            if (in_array("{$module}:write", $permissions)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.',
            ], 403);
        }

        if ($action === 'read') {
            // Read: allowed if module, module:read, or module:write exists
            if (
                in_array($module, $permissions) ||
                in_array("{$module}:read", $permissions) ||
                in_array("{$module}:write", $permissions)
            ) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Akses ditolak — role Anda tidak memiliki izin untuk modul ini.',
            ], 403);
        }

        // Granular action (e.g. reveal): must be explicitly granted, with
        // module:write as the implicit fallback so DPOs that previously had
        // write don't get locked out before role re-seeding.
        if (
            in_array("{$module}:{$action}", $permissions) ||
            in_array("{$module}:write", $permissions)
        ) {
            return $next($request);
        }

        return response()->json([
            'message' => "Akses ditolak — role Anda tidak memiliki izin {$action} untuk modul ini.",
        ], 403);
    }
}
