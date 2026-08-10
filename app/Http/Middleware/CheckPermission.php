<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use App\Services\PermissionService;
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
    public function __construct(
        private PermissionService $permissions,
        private EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $module, string $action = 'read'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Gerbang komersial, sebelum gerbang RBAC. Modul yang dicabut dari
        // sebuah organisasi tidak boleh diakses siapa pun di dalamnya —
        // sekalipun role-nya punya izin — karena organisasinya memang tidak
        // memilikinya. Menyembunyikan menu di sidebar tidak cukup: tanpa
        // penjagaan di sini, mengetik URL-nya langsung tetap membuka datanya.
        if (! $this->entitlements->allowsModule($user, $module)) {
            return response()->json([
                'message' => 'Modul ini tidak aktif untuk organisasi Anda. Hubungi administrator platform.',
            ], 403);
        }

        // Decision logic centralized in PermissionService (shared with
        // ModuleCrudController::checkPermission so the two can't drift).
        if ($this->permissions->allows($user, $module, $action)) {
            return $next($request);
        }

        // Denial messages preserved exactly per action type.
        $message = match ($action) {
            'write' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.',
            'read' => 'Akses ditolak — role Anda tidak memiliki izin untuk modul ini.',
            default => "Akses ditolak — role Anda tidak memiliki izin {$action} untuk modul ini.",
        };

        return response()->json(['message' => $message], 403);
    }
}
