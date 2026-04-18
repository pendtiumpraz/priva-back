<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPermission Middleware
 * 
 * Validates that the authenticated user's tenant_role has the required permission
 * for the given module + action (read/write).
 * 
 * Usage in routes:
 *   ->middleware('permission:ropa,read')   // requires ropa:read or ropa:write or *
 *   ->middleware('permission:ropa,write')  // requires ropa:write or *
 *   ->middleware('permission:ropa')        // alias for permission:ropa,read
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'read'): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Superadmin bypasses all permission checks
        if (in_array($user->role, ['root','superadmin'], true)) {
            return $next($request);
        }

        // Load tenant role if not loaded
        if (!$user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }

        $permissions = $user->tenantRole?->permissions ?? null;

        // If no tenant_role or no permissions array, fall back to legacy role-based check
        if (!is_array($permissions)) {
            // Legacy: admin, dpo, maker can read+write; viewer can only read
            if ($action === 'write') {
                if (in_array($user->role, ['admin', 'dpo', 'maker'])) {
                    return $next($request);
                }
                return response()->json([
                    'message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.',
                ], 403);
            }
            // read is allowed for all authenticated legacy roles
            return $next($request);
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
}
